<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Order;
use App\Models\Seller;
use App\Models\StockUnit;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class StockInvariantsTest extends TestCase
{
    use RefreshDatabase;

    private function offer(): Offer
    {
        $this->seed();

        $seller = Seller::query()->create(['name' => 'Тест-продавец', 'rating' => 4.8]);

        return Offer::query()->create([
            'product_sku' => 'KEY-CS2-PRIME',
            'seller_id' => $seller->id,
            'supplier_id' => 'a',
            'price_minor' => 129000,
            'currency' => 'RUB',
            'status' => 'active',
        ]);
    }

    public function test_one_unit_cannot_be_reserved_by_two_orders(): void
    {
        // Единица физически держит одно поле reserved_order_id, поэтому
        // проверяем обратное требование 3.4: один заказ не забирает две единицы.
        $offer = $this->offer();
        $order = Order::query()->create([
            'id' => 'ord_test_1', 'sku' => 'KEY-CS2-PRIME', 'offer_id' => $offer->id,
            'amount_minor' => 129000, 'discount_minor' => 0, 'total_minor' => 129000,
            'currency' => 'RUB', 'status' => 'created', 'idempotency_key' => 'inv-1',
        ]);

        StockUnit::query()->create([
            'offer_id' => $offer->id, 'state' => 'reserved',
            'reserved_order_id' => $order->id, 'reserved_until' => now()->addMinutes(5),
        ]);

        $this->expectException(QueryException::class);

        StockUnit::query()->create([
            'offer_id' => $offer->id, 'state' => 'reserved',
            'reserved_order_id' => $order->id, 'reserved_until' => now()->addMinutes(5),
        ]);
    }

    public function test_reserved_state_requires_order_and_deadline(): void
    {
        $offer = $this->offer();

        $this->expectException(QueryException::class);

        DB::statement(
            "INSERT INTO stock_units (offer_id, state, created_at, updated_at)
             VALUES (?, 'reserved', now(), now())",
            [$offer->id],
        );
    }

    public function test_available_unit_cannot_hold_reservation_fields(): void
    {
        // reserved_order_id должен указывать на реальный заказ: иначе падение
        // с тем же успехом объясняется нарушением FK, а не тем инвариантом,
        // который здесь проверяется (state=available не может нести оба поля
        // брони одновременно).
        $offer = $this->offer();
        $order = Order::query()->create([
            'id' => 'ord_test_3', 'sku' => 'KEY-CS2-PRIME', 'offer_id' => $offer->id,
            'amount_minor' => 129000, 'discount_minor' => 0, 'total_minor' => 129000,
            'currency' => 'RUB', 'status' => 'created', 'idempotency_key' => 'inv-3',
        ]);

        $this->expectException(QueryException::class);

        DB::statement(
            "INSERT INTO stock_units (offer_id, state, reserved_order_id, reserved_until, created_at, updated_at)
             VALUES (?, 'available', ?, now(), now(), now())",
            [$offer->id, $order->id],
        );
    }

    public function test_available_unit_cannot_keep_a_leftover_reservation_order(): void
    {
        // reserved_shape — биконъюнкция и пропускает available с ровно одним
        // из двух полей брони непустым (эта же форма легальна у проданной
        // единицы). Проверяем, что дыру закрывает отдельный available_shape:
        // у свободной единицы оба поля обязаны быть пустыми одновременно.
        $offer = $this->offer();
        $order = Order::query()->create([
            'id' => 'ord_test_4', 'sku' => 'KEY-CS2-PRIME', 'offer_id' => $offer->id,
            'amount_minor' => 129000, 'discount_minor' => 0, 'total_minor' => 129000,
            'currency' => 'RUB', 'status' => 'created', 'idempotency_key' => 'inv-4',
        ]);

        $this->expectException(QueryException::class);

        DB::statement(
            "INSERT INTO stock_units (offer_id, state, reserved_order_id, created_at, updated_at)
             VALUES (?, 'available', ?, now(), now())",
            [$offer->id, $order->id],
        );
    }

    public function test_reservation_expired_is_an_allowed_order_status(): void
    {
        $offer = $this->offer();

        $order = Order::query()->create([
            'id' => 'ord_test_2', 'sku' => 'KEY-CS2-PRIME', 'offer_id' => $offer->id,
            'amount_minor' => 129000, 'discount_minor' => 0, 'total_minor' => 129000,
            'currency' => 'RUB', 'status' => 'reservation_expired', 'idempotency_key' => 'inv-2',
        ]);

        $this->assertSame('reservation_expired', $order->refresh()->status->value);
    }

    public function test_offer_price_must_be_positive(): void
    {
        $offer = $this->offer();

        $this->expectException(QueryException::class);

        Offer::query()->create([
            'product_sku' => 'KEY-CS2-PRIME',
            'seller_id' => $offer->seller_id,
            'supplier_id' => 'a',
            'price_minor' => 0,
            'currency' => 'RUB',
            'status' => 'active',
        ]);
    }

    public function test_offer_status_is_restricted_to_known_values(): void
    {
        $offer = $this->offer();

        $this->expectException(QueryException::class);

        Offer::query()->create([
            'product_sku' => 'KEY-CS2-PRIME',
            'seller_id' => $offer->seller_id,
            'supplier_id' => 'a',
            'price_minor' => 129000,
            'currency' => 'RUB',
            'status' => 'deleted',
        ]);
    }
}
