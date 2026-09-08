<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Delivery\DeliveryState;
use App\Domain\Delivery\IssueOrderCode;
use App\Domain\Delivery\SupplierOutcome;
use App\Domain\Delivery\SupplierRegistry;
use App\Domain\Orders\OrderStatus;
use App\Domain\Stock\SaleResult;
use App\Domain\Stock\StockService;
use App\Models\Delivery;
use App\Models\Offer;
use App\Models\Order;
use App\Models\PaymentEvent;
use App\Models\Seller;
use App\Models\StockUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as OutboundRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\FakeSupplier;
use Tests\TestCase;

final class PaymentReservationTest extends TestCase
{
    use RefreshDatabase;

    private Offer $hot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Queue::fake();

        // /api/dev/pay делает настоящий сетевой POST на config('store.payment_webhook_url')
        // (http://nginx/...). На живом стенде это тот же процесс и та же
        // база (см. README). Но под make test CLI-процесс "php artisan test"
        // получает DB_DATABASE=store_test из phpunit.xml, а php-fpm за nginx
        // поднят с DB_DATABASE=store из backend/.env — это ДВА РАЗНЫХ
        // процесса с разными базами. Настоящий сетевой виток пришёл бы в
        // процесс, который вообще не видит транзакцию RefreshDatabase этого
        // теста, и платёж применился бы (если применился) не к тому заказу,
        // что проверяет тест. Http::fake перехватывает вызов и прогоняет тот
        // же payload через тот же WebhookController::handle, но внутри
        // текущего процесса — тестовая база видит эффект вебхука по-настоящему.
        Http::fake([
            config('store.payment_webhook_url') => function (OutboundRequest $request) {
                $response = $this->postJson('/api/webhook/payment', $request->data());

                return Http::response($response->json(), $response->status());
            },
        ]);

        $this->hot = Offer::query()->where('product_sku', 'KEY-CS2-PRIME')
            ->orderBy('price_minor')->firstOrFail();
    }

    private function order(string $key): Order
    {
        $id = $this->postJson('/api/orders', ['offer_id' => $this->hot->id],
            ['Idempotency-Key' => $key])->assertCreated()->json('id');

        return Order::query()->findOrFail($id);
    }

    private function webhook(Order $order, string $status = 'paid', ?string $eventId = null): void
    {
        $this->postJson('/api/webhook/payment', [
            'event_id' => $eventId ?? 'evt_'.$order->id.'_paid_1',
            'order_id' => $order->id,
            'status' => $status,
            'amount' => $order->total_minor / 100,
            'currency' => $order->currency,
            'created_at' => now()->toIso8601ZuluString(),
        ])->assertOk();
    }

    public function test_payment_sells_the_held_unit(): void
    {
        $order = $this->order('pay-1');

        $this->webhook($order);

        $unit = StockUnit::query()->where('offer_id', $this->hot->id)->firstOrFail();
        $this->assertSame('sold', $unit->state);
        $this->assertNotNull($unit->sold_at);
        $this->assertNull($unit->reserved_until);
        $this->assertSame('paid', $order->refresh()->status->value);
    }

    public function test_payment_after_deadline_still_sells_the_unit_it_still_holds(): void
    {
        $order = $this->order('pay-2');

        // Бронь просрочена, но планировщик не успел её снять: покупатель не
        // отвечает за расписание, единица всё ещё за его заказом.
        StockUnit::query()->where('reserved_order_id', $order->id)
            ->update(['reserved_until' => now()->subMinute()]);

        $this->webhook($order);

        $this->assertSame('sold', StockUnit::query()
            ->where('offer_id', $this->hot->id)->firstOrFail()->state);
        $this->assertSame('paid', $order->refresh()->status->value);
        $this->assertFalse((bool) $order->refresh()->refund_required);
    }

    public function test_late_payment_reclaims_another_unit_of_the_same_offer(): void
    {
        $order = $this->order('pay-3');

        // Бронь снята, единица вернулась в продажу, но склад пополнился:
        // оплата обязана взять другую единицу того же предложения.
        $this->artisanExpire($order);
        StockUnit::query()->create(['offer_id' => $this->hot->id, 'state' => 'available']);

        $this->webhook($order);

        $this->assertSame('paid', $order->refresh()->status->value);
        $this->assertSame(1, StockUnit::query()->where('offer_id', $this->hot->id)
            ->where('state', 'sold')->count());
        $this->assertFalse((bool) $order->refresh()->refund_required);
    }

    public function test_late_payment_without_stock_is_kept_and_flagged_for_refund(): void
    {
        $order = $this->order('pay-4');
        $this->artisanExpire($order);

        // Единственная единица этого предложения (см. CatalogSeedTest) не
        // просто просрочена — её увёл другой покупатель, пока эта ждала
        // оплаты. Без этого шага единица сама вернулась бы в продажу и
        // поздняя оплата перезахватила бы её же саму (см. ветку 2 —
        // test_late_payment_reclaims_another_unit_of_the_same_offer): здесь
        // проверяется именно ветка 3 — единиц не осталось вовсе.
        StockUnit::query()->where('offer_id', $this->hot->id)
            ->update(['state' => 'sold', 'sold_at' => now()]);

        $this->webhook($order);

        $fresh = $order->refresh();

        // Деньги пришли, товара нет: терять платёж нельзя, поэтому заказ
        // остаётся оплаченным по факту и помечается к возврату.
        $this->assertSame('out_of_stock', $fresh->status->value);
        $this->assertTrue((bool) $fresh->refund_required);
        $this->assertSame('evt_'.$order->id.'_paid_1', $fresh->paid_event_id);
    }

    public function test_applying_the_same_payment_twice_keeps_one_sold_unit(): void
    {
        $order = $this->order('pay-7');

        $this->webhook($order);
        // Тот же event_id второй раз: журнал событий обязан отсечь дубль, а
        // продажа единицы — остаться идемпотентной, без нарушения UNIQUE.
        $this->webhook($order);

        $this->assertSame(1, StockUnit::query()->where('reserved_order_id', $order->id)
            ->where('state', 'sold')->count());
        $this->assertSame('paid', $order->refresh()->status->value);
    }

    /**
     * Прямая проверка ловушки из ревью задачи 1: без проверки "уже продана"
     * внутри sellForOrder повторный вызов на заказ с уже проданной единицей
     * проваливался бы в перезахват и пытался бы поставить reserved_order_id
     * того же заказа на ВТОРУЮ единицу — нарушение частичного UNIQUE
     * stock_units_one_order_per_unit, то есть 500 вместо идемпотентности.
     * Метод вызывается напрямую, в обход журнала событий: та идемпотентность
     * (test_applying_the_same_payment_twice_keeps_one_sold_unit выше) не
     * доходит до повторного sellForOrder вовсе, потому что дубль event_id
     * отсекается раньше — эта проверка нужна как защита сама по себе.
     */
    public function test_sell_for_order_is_reentrant_after_the_unit_is_already_sold(): void
    {
        $order = $this->order('pay-8');
        $stock = app(StockService::class);

        $this->assertSame(SaleResult::SoldHeld, $stock->sellForOrder($order->id, $this->hot->id));

        // Склад пополнился между вызовами — соблазн перезахвата есть, но
        // заказ уже держит свою (проданную) единицу и трогать другую не должен.
        StockUnit::query()->create(['offer_id' => $this->hot->id, 'state' => 'available']);

        $this->assertSame(SaleResult::SoldHeld, $stock->sellForOrder($order->id, $this->hot->id));
        $this->assertSame(1, StockUnit::query()->where('reserved_order_id', $order->id)->count());
        $this->assertSame(1, StockUnit::query()->where('offer_id', $this->hot->id)
            ->where('state', 'sold')->count());
    }

    public function test_repeated_payment_attempt_creates_no_second_event(): void
    {
        $order = $this->order('pay-5');

        $this->postJson("/api/dev/pay/{$order->id}?result=success")->assertOk();
        $this->postJson("/api/dev/pay/{$order->id}?result=success")->assertOk();

        // Ключ события детерминирован, поэтому повтор после обрыва связи
        // отсекается первичным ключом журнала, а не ветвлением по статусу.
        $this->assertSame(1, PaymentEvent::query()->where('order_id', $order->id)->count());
    }

    public function test_payment_is_refused_while_the_price_differs(): void
    {
        $order = $this->order('pay-6');

        Offer::query()->whereKey($this->hot->id)
            ->update(['price_minor' => $this->hot->price_minor + 5000]);

        $this->postJson("/api/dev/pay/{$order->id}?result=success")
            ->assertStatus(409)
            ->assertJsonPath('reason', 'price_changed')
            ->assertJsonPath('current_price_minor', $this->hot->price_minor + 5000);

        $this->assertSame(0, PaymentEvent::query()->where('order_id', $order->id)->count());
    }

    /**
     * Спека 6.5: offers.supplier_id выбирает, кто выдаёт код первым, а не
     * порядок поставщиков в конфиге. Конфиг здесь намеренно перечисляет 'a'
     * первым — как и everywhere в проекте (см. IssueOrderCodeTest) — чтобы
     * убедиться, что обход стартует не оттуда, а от supplier_id предложения.
     * Правило первого этапа не меняется: к резервному только по однозначному
     * out_of_stock — этим и объясняется, что 'a' здесь не вызывается вовсе.
     */
    public function test_issue_starts_with_the_offers_supplier_not_the_first_configured_one(): void
    {
        $seller = Seller::query()->create(['name' => 'Продавец через b', 'rating' => 4.7]);
        $offer = Offer::query()->create([
            'product_sku' => 'KEY-CS2-PRIME',
            'seller_id' => $seller->id,
            'supplier_id' => 'b',
            'price_minor' => 150000,
            'currency' => 'RUB',
            'status' => 'active',
        ]);

        $order = Order::query()->create([
            'id' => 'ord_'.strtolower((string) Str::ulid()),
            'sku' => 'KEY-CS2-PRIME',
            'offer_id' => $offer->id,
            'amount_minor' => $offer->price_minor,
            'discount_minor' => 0,
            'total_minor' => $offer->price_minor,
            'currency' => 'RUB',
            'status' => OrderStatus::Paid,
            'idempotency_key' => 'supplier-order-test',
        ]);

        $delivery = Delivery::query()->create([
            'order_id' => $order->id,
            'request_id' => Delivery::requestIdFor($order->id),
            'state' => DeliveryState::Pending->value,
            'attempts' => 0,
        ]);

        $a = new FakeSupplier('a', [SupplierOutcome::ok('AAAA-0000-0000')]);
        $b = new FakeSupplier('b', [SupplierOutcome::ok('BBBB-1111-1111')]);

        (new IssueOrderCode(new SupplierRegistry(['a' => $a, 'b' => $b]), attemptsPerSupplier: 3, lockSeconds: 45))
            ($delivery->id);

        $fresh = $order->refresh();
        $this->assertSame(OrderStatus::Delivered, $fresh->status);
        $this->assertSame('BBBB-1111-1111', $fresh->delivered_code);
        $this->assertSame('b', $fresh->delivered_by);
        $this->assertCount(1, $b->calls);
        $this->assertCount(0, $a->calls, 'основной поставщик определяется предложением, а не конфигом');
    }

    private function artisanExpire(Order $order): void
    {
        $this->postJson("/api/dev/reservations/{$order->id}/expire")->assertOk();
        $this->artisan('reservations:release')->assertSuccessful();
    }
}
