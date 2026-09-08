<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Order;
use App\Models\PromoRedemption;
use App\Models\StockUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class ReservationTest extends TestCase
{
    use RefreshDatabase;

    private Offer $hot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Queue::fake();

        $this->hot = Offer::query()
            ->where('product_sku', 'KEY-CS2-PRIME')
            ->orderBy('price_minor')
            ->firstOrFail();
    }

    public function test_order_reserves_exactly_one_unit_with_a_deadline(): void
    {
        $response = $this->postJson('/api/orders', ['offer_id' => $this->hot->id],
            ['Idempotency-Key' => 'res-1']);

        $response->assertCreated()
            ->assertJsonPath('offer_id', $this->hot->id)
            ->assertJsonPath('offer.available', 0);

        $this->assertNotNull($response->json('reservation.expires_at'));
        $this->assertGreaterThan(0, $response->json('reservation.seconds_left'));

        $units = StockUnit::query()->where('offer_id', $this->hot->id)->get();
        $this->assertCount(1, $units);
        $this->assertSame('reserved', $units[0]->state);
        $this->assertSame($response->json('id'), $units[0]->reserved_order_id);
    }

    public function test_second_buyer_gets_sold_out_with_a_cheaper_alternative_offered(): void
    {
        $this->postJson('/api/orders', ['offer_id' => $this->hot->id],
            ['Idempotency-Key' => 'res-2'])->assertCreated();

        $second = $this->postJson('/api/orders', ['offer_id' => $this->hot->id],
            ['Idempotency-Key' => 'res-3']);

        $second->assertStatus(409)->assertJsonPath('reason', 'sold_out');

        $this->assertNotNull($second->json('alternative.offer_id'));
        $this->assertNotSame($this->hot->id, $second->json('alternative.offer_id'));
        $this->assertGreaterThan(0, $second->json('alternative.available'));

        // Проигравшему не должно остаться заказа: платить за воздух нечем.
        $this->assertSame(1, Order::query()->count());
    }

    public function test_double_click_returns_the_same_order_and_the_same_unit(): void
    {
        $headers = ['Idempotency-Key' => 'res-double'];

        $first = $this->postJson('/api/orders', ['offer_id' => $this->hot->id], $headers);
        $second = $this->postJson('/api/orders', ['offer_id' => $this->hot->id], $headers);

        $first->assertCreated();
        $second->assertOk();

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, StockUnit::query()->where('state', 'reserved')
            ->where('offer_id', $this->hot->id)->count());
    }

    public function test_expired_reservation_of_another_order_is_reused(): void
    {
        $first = $this->postJson('/api/orders', ['offer_id' => $this->hot->id],
            ['Idempotency-Key' => 'res-exp-1'])->assertCreated();

        // Бронь просрочена, но планировщик ещё не проснулся: захват обязан
        // подобрать её лениво, иначе товар «залипает» до тика расписания.
        StockUnit::query()->where('reserved_order_id', $first->json('id'))
            ->update(['reserved_until' => now()->subSecond()]);

        $second = $this->postJson('/api/orders', ['offer_id' => $this->hot->id],
            ['Idempotency-Key' => 'res-exp-2']);

        $second->assertCreated();
        $this->assertSame($second->json('id'), StockUnit::query()
            ->where('offer_id', $this->hot->id)->firstOrFail()->reserved_order_id);
    }

    public function test_sold_out_does_not_burn_a_promocode_slot(): void
    {
        $this->postJson('/api/orders', ['offer_id' => $this->hot->id],
            ['Idempotency-Key' => 'res-promo-1'])->assertCreated();

        $this->postJson('/api/orders',
            ['offer_id' => $this->hot->id, 'promo_code' => 'WELCOME10'],
            ['Idempotency-Key' => 'res-promo-2'])->assertStatus(409);

        // Откат транзакции обязан вернуть и слот промокода: иначе отказ по
        // складу тихо съедал бы лимит.
        $this->assertSame(0, PromoRedemption::query()->count());
    }
}
