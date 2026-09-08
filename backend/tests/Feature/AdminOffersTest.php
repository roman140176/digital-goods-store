<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Orders\OrderStatus;
use App\Jobs\DeliverOrder;
use App\Models\Offer;
use App\Models\Order;
use App\Models\StockUnit;
use App\Models\StreamEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminOffersTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'admin-secret-token';

    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->offer = Offer::query()->orderBy('id')->firstOrFail();
    }

    public function test_price_change_publishes_an_event(): void
    {
        StreamEvent::query()->delete();

        $this->post("/admin/offers/{$this->offer->id}/price?token=".self::TOKEN,
            ['price_minor' => 111100])->assertRedirect();

        $this->assertSame(111100, $this->offer->refresh()->price_minor);

        $event = StreamEvent::query()->latest('id')->firstOrFail();
        $this->assertSame('offer.updated', $event->type);
        $this->assertSame(111100, $event->payload['price_minor']);
    }

    public function test_leave_one_keeps_exactly_one_free_unit(): void
    {
        StockUnit::query()->create(['offer_id' => $this->offer->id, 'state' => 'available']);

        $this->post("/admin/offers/{$this->offer->id}/leave-one?token=".self::TOKEN)
            ->assertRedirect();

        $this->assertSame(1, StockUnit::query()->where('offer_id', $this->offer->id)
            ->where('state', 'available')->count());
    }

    public function test_stock_change_never_touches_reserved_or_sold_units(): void
    {
        StockUnit::query()->where('offer_id', $this->offer->id)->limit(1)
            ->update(['state' => 'sold', 'sold_at' => now()]);

        $this->post("/admin/offers/{$this->offer->id}/stock?token=".self::TOKEN,
            ['units' => 0])->assertRedirect();

        // Проданную единицу удалять нельзя: на неё ссылается выданный заказ.
        $this->assertSame(1, StockUnit::query()->where('offer_id', $this->offer->id)
            ->where('state', 'sold')->count());
    }

    public function test_admin_endpoints_require_the_token(): void
    {
        $this->post("/admin/offers/{$this->offer->id}/price", ['price_minor' => 1])
            ->assertStatus(403);
    }

    public function test_toggle_hides_offer_and_publishes_offer_gone(): void
    {
        StreamEvent::query()->delete();

        $this->post("/admin/offers/{$this->offer->id}/toggle?token=".self::TOKEN)
            ->assertRedirect();

        $this->assertSame('hidden', $this->offer->refresh()->status);

        $event = StreamEvent::query()->latest('id')->firstOrFail();
        $this->assertSame('offer.gone', $event->type);
        $this->assertSame($this->offer->id, $event->payload['offer_id']);
    }

    /**
     * Решение задачи 13: единственный ручной путь вернуть в выдачу заказ,
     * который был оплачен, когда товара уже не было (out_of_stock +
     * refund_required, см. ApplyPaymentEvent::applyPaidWithoutStock). Склад
     * пополнили — повторная выдача обязана сама захватить единицу и снять
     * отметку о возврате, а не просто повторить попытку похода к поставщику
     * вхолостую.
     */
    public function test_redelivery_reclaims_a_unit_for_refund_required_order(): void
    {
        Queue::fake();

        // Имитируем реальный сценарий: на момент оплаты свободных единиц уже
        // не было (иначе applyPaidWithoutStock вообще не наступил бы).
        StockUnit::query()->where('offer_id', $this->offer->id)->delete();

        $order = Order::query()->create([
            'id' => 'ord_'.Str::lower((string) Str::ulid()),
            'sku' => $this->offer->product_sku,
            'offer_id' => $this->offer->id,
            'amount_minor' => $this->offer->price_minor,
            'discount_minor' => 0,
            'total_minor' => $this->offer->price_minor,
            'currency' => $this->offer->currency,
            'status' => OrderStatus::OutOfStock,
            'idempotency_key' => (string) Str::uuid(),
            'refund_required' => true,
        ]);

        // Склад пополнили — ровно то действие, которое админ выполнит на
        // демонстрации перед повторной выдачей.
        $this->post("/admin/offers/{$this->offer->id}/stock?token=".self::TOKEN,
            ['units' => 1])->assertRedirect();

        $this->postJson('/admin/orders/'.$order->id.'/redeliver?token='.self::TOKEN)
            ->assertOk()
            ->assertJsonPath('redelivered', true);

        $this->assertSame(0, StockUnit::query()->where('offer_id', $this->offer->id)
            ->where('state', 'available')->count());
        $this->assertSame(1, StockUnit::query()->where('offer_id', $this->offer->id)
            ->where('state', 'sold')
            ->where('reserved_order_id', $order->id)->count());
        $this->assertFalse((bool) $order->refresh()->refund_required);
        Queue::assertPushed(DeliverOrder::class);
    }

    /**
     * Пополнения нет — ответ обязан честно сказать, что выдавать нечего, и
     * не создавать задачу выдачи: иначе воркер сходил бы к поставщику вхолостую,
     * а refund_required тихо потерялся бы без реально проданного товара.
     */
    public function test_redelivery_without_stock_keeps_refund_required_and_does_nothing(): void
    {
        Queue::fake();

        StockUnit::query()->where('offer_id', $this->offer->id)->delete();

        $order = Order::query()->create([
            'id' => 'ord_'.Str::lower((string) Str::ulid()),
            'sku' => $this->offer->product_sku,
            'offer_id' => $this->offer->id,
            'amount_minor' => $this->offer->price_minor,
            'discount_minor' => 0,
            'total_minor' => $this->offer->price_minor,
            'currency' => $this->offer->currency,
            'status' => OrderStatus::OutOfStock,
            'idempotency_key' => (string) Str::uuid(),
            'refund_required' => true,
        ]);

        $this->postJson('/admin/orders/'.$order->id.'/redeliver?token='.self::TOKEN)
            ->assertStatus(409)
            ->assertJsonPath('redelivered', false);

        $this->assertTrue((bool) $order->refresh()->refund_required);
        $this->assertSame(OrderStatus::OutOfStock->value, $order->status->value);
        Queue::assertNothingPushed();
    }
}
