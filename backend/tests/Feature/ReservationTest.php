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

    public function test_second_buyer_gets_sold_out_with_another_sellers_offer(): void
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

        // Захват склада внутри транзакции идёт СТРОГО раньше резерва
        // промокода (см. порядок в CreateOrder и 6.2 спеки): если единицы
        // нет, до попытки занять слот промокода дело не доходит вовсе, а не
        // "доходит, но откатывается". Ноль записей здесь доказывает именно
        // это — что резерва не было, а не что он был снят откатом.
        $this->assertSame(0, PromoRedemption::query()->count());
    }

    /**
     * Ревью после задачи 4a, Important 1. Резолвинг предложения по sku
     * зависит от того, есть ли ПРЯМО СЕЙЧАС свободная единица (см.
     * StockService::bestOfferFor) — а у повторного вызова с тем же ключом
     * идемпотентности её вполне может не быть: она уже захвачена ПЕРВЫМ же
     * вызовом ЭТОГО клиента. Раньше `resolveOffer()` вызывался до поиска
     * существующего заказа по ключу, поэтому повтор, попавший в момент,
     * когда остальные предложения позиции успели распродать, получал
     * ложный 409 sold_out вместо своего же заказа.
     */
    public function test_repeated_request_by_sku_returns_existing_order_even_if_stock_is_gone_now(): void
    {
        $headers = ['Idempotency-Key' => 'res-retry-after-sellout'];

        // Первый вызов забирает единственную единицу самого дешёвого
        // предложения — резолвинг по sku внутри CreateOrder выбирает именно
        // его, ровно как проверяет CatalogSeedTest.
        $first = $this->postJson('/api/orders', ['sku' => $this->hot->product_sku], $headers);
        $first->assertCreated();

        // Все ОСТАЛЬНЫЕ предложения позиции распроданы кем-то другим, пока
        // клиент ждал ответ на оборвавшемся соединении: bestOfferFor не
        // нашёл бы теперь вообще ничего, если бы резолвил заново.
        $otherOfferIds = Offer::query()
            ->where('product_sku', $this->hot->product_sku)
            ->where('id', '!=', $this->hot->id)
            ->pluck('id');

        StockUnit::query()->whereIn('offer_id', $otherOfferIds)
            ->update(['state' => 'sold', 'sold_at' => now()]);

        // Повтор с тем же ключом и тем же sku обязан вернуть уже
        // существующий заказ, а не 409 sold_out: клиент просто не получил
        // ответ на первый раз, бронь у него уже есть.
        $second = $this->postJson('/api/orders', ['sku' => $this->hot->product_sku], $headers);

        $second->assertOk();
        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, Order::query()->count());
    }

    /**
     * Ревью после задачи 4a, Important 2. status — единственный механизм,
     * которым предложение снимается с продажи без удаления строки (админ
     * скрыл его, пока у покупателя уже была открыта страница с этим
     * offer_id). Путь по sku фильтрует только активные предложения
     * (StockService::firstAvailableOfferId), а явный offer_id раньше не
     * проверял статус вовсе — единица захватывалась в обход скрытия.
     */
    public function test_explicit_offer_id_to_a_hidden_offer_is_sold_out_too(): void
    {
        $this->hot->update(['status' => 'hidden']);

        $response = $this->postJson('/api/orders', ['offer_id' => $this->hot->id],
            ['Idempotency-Key' => 'res-hidden-1']);

        $response->assertStatus(409)->assertJsonPath('reason', 'sold_out');

        $this->assertNotNull($response->json('alternative.offer_id'));
        $this->assertNotSame($this->hot->id, $response->json('alternative.offer_id'));

        // Единица скрытого предложения не должна была тронуться захватом.
        $this->assertSame('available', StockUnit::query()
            ->where('offer_id', $this->hot->id)->firstOrFail()->state);
        $this->assertSame(0, Order::query()->count());
    }
}
