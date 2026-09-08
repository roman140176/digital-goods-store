<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Delivery\DeliveryState;
use App\Domain\Orders\OrderStatus;
use App\Jobs\DeliverOrder;
use App\Models\Delivery;
use App\Models\Offer;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminRedeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Queue::fake();
    }

    private function order(OrderStatus $status, ?string $code = null): Order
    {
        // Цена живёт в предложении, не в товаре (задача 4b), а orders.offer_id
        // теперь NOT NULL — заказ ссылается на реальное активное предложение
        // сида, а не на константу, оторванную от каталога.
        $offer = Offer::query()->where('product_sku', 'KEY-CS2-PRIME')
            ->where('status', 'active')->orderBy('price_minor')->firstOrFail();

        return Order::query()->create([
            'id' => 'ord_'.Str::lower((string) Str::ulid()),
            'sku' => 'KEY-CS2-PRIME',
            'offer_id' => $offer->id,
            'amount_minor' => $offer->price_minor,
            'discount_minor' => 0,
            'total_minor' => $offer->price_minor,
            'currency' => 'RUB',
            'status' => $status,
            'idempotency_key' => (string) Str::uuid(),
            'delivered_code' => $code,
            'delivered_by' => $code === null ? null : 'a',
            'delivered_at' => $code === null ? null : now(),
        ]);
    }

    public function test_admin_requires_token(): void
    {
        $this->get('/admin/orders')->assertForbidden();
        $this->get('/admin/orders?token=wrong')->assertForbidden();
        $this->get('/admin/orders?token='.config('store.admin_token'))->assertOk();
    }

    public function test_recoverable_order_is_queued_for_redelivery(): void
    {
        $order = $this->order(OrderStatus::OutOfStock);
        Delivery::query()->create([
            'order_id' => $order->id,
            'request_id' => Delivery::requestIdFor($order->id),
            'state' => DeliveryState::OutOfStock->value,
            'attempts' => 2,
        ]);

        $this->postJson('/admin/orders/'.$order->id.'/redeliver?token='.config('store.admin_token'))
            ->assertOk()
            ->assertJsonPath('redelivered', true);

        $this->assertDatabaseHas('deliveries', [
            'order_id' => $order->id,
            'state' => DeliveryState::Pending->value,
        ]);
        Queue::assertPushed(DeliverOrder::class);
    }

    public function test_redelivery_of_delivered_order_does_nothing(): void
    {
        $order = $this->order(OrderStatus::Delivered, 'AAAA-BBBB-CCCC');

        $this->postJson('/admin/orders/'.$order->id.'/redeliver?token='.config('store.admin_token'))
            ->assertOk()
            ->assertJsonPath('redelivered', false)
            ->assertJsonPath('code', 'AAAA-BBBB-CCCC');

        Queue::assertNothingPushed();
    }

    public function test_parallel_redelivery_requests_keep_single_delivery_row(): void
    {
        $order = $this->order(OrderStatus::DeliveryFailed);
        Delivery::query()->create([
            'order_id' => $order->id,
            'request_id' => Delivery::requestIdFor($order->id),
            'state' => DeliveryState::Failed->value,
            'attempts' => 1,
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/admin/orders/'.$order->id.'/redeliver?token='.config('store.admin_token'))->assertOk();
        }

        // Строка выдачи одна, и request_id у неё не менялся: сколько бы раз
        // ни нажали, поставщик получит один и тот же ключ запроса.
        $this->assertDatabaseCount('deliveries', 1);
        $this->assertDatabaseHas('deliveries', [
            'order_id' => $order->id,
            'request_id' => 'req_'.$order->id,
        ]);
    }
}
