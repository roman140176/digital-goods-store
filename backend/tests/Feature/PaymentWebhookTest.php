<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Orders\OrderStatus;
use App\Domain\Payments\ApplyPaymentEvent;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Promocode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Queue::fake();
    }

    private string $lastEventId = '';

    /** @param array<string, mixed> $overrides */
    private function event(string $orderId, array $overrides = []): array
    {
        $this->lastEventId = 'evt_'.bin2hex(random_bytes(6));

        return array_merge([
            'event_id' => $this->lastEventId,
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => 1290,
            'currency' => 'RUB',
            'created_at' => now()->toIso8601ZuluString(),
        ], $overrides);
    }

    private function createOrder(string $sku = 'KEY-CS2-PRIME', ?string $promo = null): Order
    {
        $payload = $promo === null ? ['sku' => $sku] : ['sku' => $sku, 'promo_code' => $promo];

        $response = $this->postJson('/api/orders', $payload, ['Idempotency-Key' => 'key-'.bin2hex(random_bytes(4))]);
        $response->assertCreated();

        return Order::query()->findOrFail($response->json('id'));
    }

    public function test_paid_event_marks_order_paid_and_creates_single_delivery(): void
    {
        $order = $this->createOrder();

        $this->postJson('/api/webhook/payment', $this->event($order->id))
            ->assertOk()
            ->assertJsonPath('outcome', 'applied');

        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
        $this->assertDatabaseHas('deliveries', [
            'order_id' => $order->id,
            'request_id' => 'req_'.$order->id,
            'state' => 'pending',
        ]);
        $this->assertDatabaseCount('deliveries', 1);
    }

    public function test_repeated_event_id_changes_nothing(): void
    {
        $order = $this->createOrder();
        $event = $this->event($order->id);

        $this->postJson('/api/webhook/payment', $event)->assertJsonPath('outcome', 'applied');
        $this->postJson('/api/webhook/payment', $event)->assertOk()->assertJsonPath('outcome', 'duplicate');

        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
        $this->assertDatabaseCount('payment_events', 1);
        $this->assertDatabaseCount('deliveries', 1);
    }

    public function test_older_failed_event_after_paid_is_stale(): void
    {
        $order = $this->createOrder();
        $now = now();

        $this->postJson('/api/webhook/payment', $this->event($order->id, ['created_at' => $now->toIso8601ZuluString()]))
            ->assertJsonPath('outcome', 'applied');

        $this->postJson('/api/webhook/payment', $this->event($order->id, [
            'status' => 'failed',
            'created_at' => $now->copy()->subMinutes(2)->toIso8601ZuluString(),
        ]))->assertOk()->assertJsonPath('outcome', 'stale');

        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
    }

    public function test_newer_failed_event_after_paid_is_ignored(): void
    {
        $order = $this->createOrder();

        $this->postJson('/api/webhook/payment', $this->event($order->id))->assertJsonPath('outcome', 'applied');

        $this->postJson('/api/webhook/payment', $this->event($order->id, [
            'status' => 'failed',
            'created_at' => now()->addMinutes(2)->toIso8601ZuluString(),
        ]))->assertOk()->assertJsonPath('outcome', 'ignored');

        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
    }

    public function test_event_arriving_before_order_is_parked_and_applied_on_creation(): void
    {
        $orderId = 'ord_early_'.bin2hex(random_bytes(4));

        $this->postJson('/api/webhook/payment', $this->event($orderId))
            ->assertOk()
            ->assertJsonPath('outcome', 'parked_no_order');

        $this->assertDatabaseHas('payment_events', ['order_id' => $orderId, 'outcome' => 'parked_no_order']);

        $this->postJson('/api/dev/orders', ['id' => $orderId, 'sku' => 'KEY-CS2-PRIME'], ['Idempotency-Key' => $orderId])
            ->assertCreated();

        $this->assertSame(OrderStatus::Paid, Order::query()->findOrFail($orderId)->status);
        $this->assertDatabaseHas('payment_events', ['order_id' => $orderId, 'outcome' => 'applied']);
        $this->assertDatabaseCount('deliveries', 1);
    }

    public function test_amount_mismatch_does_not_mark_order_paid(): void
    {
        $order = $this->createOrder();

        $this->postJson('/api/webhook/payment', $this->event($order->id, ['amount' => 1]))
            ->assertOk()
            ->assertJsonPath('outcome', 'amount_mismatch');

        $this->assertSame(OrderStatus::Created, $order->refresh()->status);
        $this->assertDatabaseCount('deliveries', 0);
    }

    public function test_failed_payment_keeps_promo_usage(): void
    {
        $order = $this->createOrder('KEY-CS2-PRIME', 'LIMIT3');
        $this->assertSame(1, Promocode::query()->find('LIMIT3')?->used_count);

        $this->postJson('/api/webhook/payment', $this->event($order->id, [
            'status' => 'failed',
            'amount' => $order->total_minor / 100,
        ]))->assertOk()->assertJsonPath('outcome', 'applied');

        $this->assertSame(OrderStatus::PaymentFailed, $order->refresh()->status);
        $this->assertSame(
            1,
            Promocode::query()->find('LIMIT3')?->used_count,
            'слот принадлежит заказу: платёж может подтвердиться позже',
        );
    }

    /**
     * Регрессия. Пока отказ оплаты возвращал использование в лимит, цикл
     * «отказ → новый заказ → запоздавшее подтверждение» позволял применить
     * код сколько угодно раз: воскресший заказ сохранял скидку, не занимая
     * слот заново.
     */
    public function test_payment_confirmed_after_failure_does_not_spend_a_second_usage(): void
    {
        $order = $this->createOrder('KEY-CS2-PRIME', 'ONCEONLY');
        $discount = $order->discount_minor;
        $this->assertGreaterThan(0, $discount);

        $this->postJson('/api/webhook/payment', $this->event($order->id, [
            'status' => 'failed',
            'amount' => $order->total_minor / 100,
            'created_at' => now()->subMinute()->toIso8601ZuluString(),
        ]))->assertOk()->assertJsonPath('outcome', 'applied');

        $this->assertSame(OrderStatus::PaymentFailed, $order->refresh()->status);

        // Платёж подтвердился позже: заказ обязан ожить, деньги терять нельзя.
        $this->postJson('/api/webhook/payment', $this->event($order->id, [
            'amount' => $order->total_minor / 100,
            'created_at' => now()->toIso8601ZuluString(),
        ]))->assertOk()->assertJsonPath('outcome', 'applied');

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame($discount, $order->discount_minor, 'скидка воскресшего заказа сохраняется');

        // И при этом лимит остался соблюдён: код применён ровно один раз.
        $this->assertSame(1, Promocode::query()->find('ONCEONLY')?->used_count);
        $this->postJson('/api/orders', ['sku' => 'KEY-GTA5', 'promo_code' => 'ONCEONLY'], [
            'Idempotency-Key' => 'after-resurrection',
        ])->assertStatus(422)->assertJsonPath('reason', 'limit_reached');
    }

    /**
     * Регрессия. Парковка события — не завершение обработки: заказа ещё нет.
     * Если он появится, а быстрый путь применения парковку не увидит (она
     * закоммитилась позже его выборки — так и происходит, когда вебхук и
     * создание заказа идут одновременно), оплату обязан дослать планировщик.
     * Раньше припаркованное событие помечалось обработанным, и оплата
     * терялась навсегда.
     */
    public function test_parked_event_is_recovered_when_order_appears_later(): void
    {
        $orderId = 'ord_'.Str::lower((string) Str::ulid());
        $events = app(ApplyPaymentEvent::class);

        $this->postJson('/api/webhook/payment', $this->event($orderId))
            ->assertOk()
            ->assertJsonPath('outcome', 'parked_no_order');

        $this->assertDatabaseHas('payment_events', ['event_id' => $this->lastEventId, 'processed_at' => null]);
        $this->assertSame(0, $events->applyUnprocessed(0), 'пока заказа нет, событие не трогаем');

        // Заказ появляется в обход быстрого пути — ровно то, что даёт гонка.
        // Цена — из реального активного предложения сида: orders.offer_id
        // теперь NOT NULL (задача 4b).
        $offer = Offer::query()->where('product_sku', 'KEY-CS2-PRIME')
            ->where('status', 'active')->orderBy('price_minor')->firstOrFail();

        Order::query()->create([
            'id' => $orderId,
            'sku' => 'KEY-CS2-PRIME',
            'offer_id' => $offer->id,
            'amount_minor' => $offer->price_minor,
            'discount_minor' => 0,
            'total_minor' => $offer->price_minor,
            'currency' => 'RUB',
            'status' => OrderStatus::Created,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        // Планировщик берёт события «старше N секунд». Сдвигаем время вперёд,
        // чтобы проверка не зависела от того, чьи микросекунды старше —
        // приложения или базы: в тесте всё происходит в одну миллисекунду.
        $this->travel(5)->seconds();

        $this->assertSame(1, $events->applyUnprocessed(1));

        $order = Order::query()->findOrFail($orderId);
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertDatabaseHas('deliveries', ['order_id' => $orderId]);
    }

    /**
     * Метки в контракте с секундной точностью, поэтому отказ и подтверждение
     * одной секунды — обычное дело. На ничьей побеждает оплата: потерять
     * деньги хуже, чем лишний раз оживить заказ.
     */
    public function test_paid_wins_a_timestamp_tie_against_failed(): void
    {
        $order = $this->createOrder();
        $sameSecond = now()->startOfSecond()->toIso8601ZuluString();

        $this->postJson('/api/webhook/payment', $this->event($order->id, [
            'status' => 'failed',
            'created_at' => $sameSecond,
        ]))->assertOk()->assertJsonPath('outcome', 'applied');

        $this->postJson('/api/webhook/payment', $this->event($order->id, [
            'created_at' => $sameSecond,
        ]))->assertOk()->assertJsonPath('outcome', 'applied');

        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
        $this->assertDatabaseHas('deliveries', ['order_id' => $order->id]);
    }

    public function test_currency_mismatch_does_not_mark_order_paid(): void
    {
        $order = $this->createOrder();

        $this->postJson('/api/webhook/payment', $this->event($order->id, ['currency' => 'USD']))
            ->assertOk()
            ->assertJsonPath('outcome', 'currency_mismatch');

        $this->assertSame(OrderStatus::Created, $order->refresh()->status);
        $this->assertDatabaseCount('deliveries', 0);
    }

    public function test_out_of_range_amount_is_rejected_by_validation(): void
    {
        $order = $this->createOrder();

        // 5xx заставил бы платёжную систему повторять отравленное событие вечно.
        $this->postJson('/api/webhook/payment', $this->event($order->id, ['amount' => 1e15]))
            ->assertStatus(422);

        $this->assertSame(OrderStatus::Created, $order->refresh()->status);
    }

    public function test_delivered_order_is_never_changed_by_late_events(): void
    {
        $order = $this->createOrder();
        $order->forceFill([
            'status' => OrderStatus::Delivered->value,
            'delivered_code' => 'AAAA-BBBB-CCCC',
            'delivered_by' => 'a',
            'delivered_at' => now(),
        ])->save();

        $this->postJson('/api/webhook/payment', $this->event($order->id, ['status' => 'failed']))
            ->assertOk()
            ->assertJsonPath('outcome', 'ignored_terminal');

        $fresh = $order->refresh();
        $this->assertSame(OrderStatus::Delivered, $fresh->status);
        $this->assertSame('AAAA-BBBB-CCCC', $fresh->delivered_code);
    }
}
