<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Orders\OrderStatus;
use App\Models\Order;
use App\Models\Promocode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

    /** @param array<string, mixed> $overrides */
    private function event(string $orderId, array $overrides = []): array
    {
        return array_merge([
            'event_id' => 'evt_'.bin2hex(random_bytes(6)),
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

    public function test_failed_payment_releases_promo_usage(): void
    {
        $order = $this->createOrder('KEY-CS2-PRIME', 'LIMIT3');
        $this->assertSame(1, Promocode::query()->find('LIMIT3')?->used_count);

        $this->postJson('/api/webhook/payment', $this->event($order->id, [
            'status' => 'failed',
            'amount' => $order->total_minor / 100,
        ]))->assertOk()->assertJsonPath('outcome', 'applied');

        $this->assertSame(OrderStatus::PaymentFailed, $order->refresh()->status);
        $this->assertSame(0, Promocode::query()->find('LIMIT3')?->used_count);
        $this->assertDatabaseMissing('promo_redemptions', ['order_id' => $order->id, 'released_at' => null]);
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
