<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Order;
use App\Models\Promocode;
use App\Models\PromoRedemption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class RepriceOrderTest extends TestCase
{
    use RefreshDatabase;

    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Queue::fake();

        $this->offer = Offer::query()->where('product_sku', 'KEY-GTA5')
            ->orderBy('price_minor')->firstOrFail();
    }

    public function test_accepting_a_new_price_recalculates_the_discount_without_a_new_slot(): void
    {
        $id = $this->postJson('/api/orders',
            ['offer_id' => $this->offer->id, 'promo_code' => 'WELCOME10'],
            ['Idempotency-Key' => 'rp-1'])->assertCreated()->json('id');

        $newPrice = $this->offer->price_minor + 10000;
        Offer::query()->whereKey($this->offer->id)->update(['price_minor' => $newPrice]);

        $this->postJson("/api/orders/{$id}/reprice", ['expected_price_minor' => $newPrice])
            ->assertOk()
            ->assertJsonPath('amount_minor', $newPrice)
            ->assertJsonPath('discount_minor', (int) round($newPrice * 0.10))
            ->assertJsonPath('total_minor', $newPrice - (int) round($newPrice * 0.10));

        // Слот принадлежит заказу и не перевыделяется: иначе принятие цены
        // сжигало бы лимит промокода второй раз.
        $this->assertSame(1, PromoRedemption::query()->where('order_id', $id)->count());
    }

    public function test_reprice_is_idempotent(): void
    {
        $id = $this->postJson('/api/orders', ['offer_id' => $this->offer->id],
            ['Idempotency-Key' => 'rp-2'])->assertCreated()->json('id');

        $this->postJson("/api/orders/{$id}/reprice",
            ['expected_price_minor' => $this->offer->price_minor])->assertOk();
        $this->postJson("/api/orders/{$id}/reprice",
            ['expected_price_minor' => $this->offer->price_minor])->assertOk();

        $this->assertSame($this->offer->price_minor,
            Order::query()->findOrFail($id)->amount_minor);
    }

    public function test_reprice_refuses_a_stale_expectation(): void
    {
        $id = $this->postJson('/api/orders', ['offer_id' => $this->offer->id],
            ['Idempotency-Key' => 'rp-3'])->assertCreated()->json('id');

        Offer::query()->whereKey($this->offer->id)->update(['price_minor' => 999999]);

        $this->postJson("/api/orders/{$id}/reprice",
            ['expected_price_minor' => $this->offer->price_minor])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'price_changed');
    }

    public function test_reprice_refuses_a_paid_order(): void
    {
        $id = $this->postJson('/api/orders', ['offer_id' => $this->offer->id],
            ['Idempotency-Key' => 'rp-4'])->assertCreated()->json('id');

        Order::query()->whereKey($id)->update(['status' => 'paid']);

        $this->postJson("/api/orders/{$id}/reprice",
            ['expected_price_minor' => $this->offer->price_minor])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'order_not_repriceable');
    }

    /**
     * Механика reprice одна и та же в обе стороны: подешевевший товар
     * принимается так же, как подорожавший, — плашка на витрине из 6.6 спеки
     * прямо предусматривает эту ветку («для подешевевшего товара — та же
     * механика»).
     */
    public function test_accepting_a_lower_price_recalculates_the_discount_downward(): void
    {
        $id = $this->postJson('/api/orders',
            ['offer_id' => $this->offer->id, 'promo_code' => 'WELCOME10'],
            ['Idempotency-Key' => 'rp-5'])->assertCreated()->json('id');

        $newPrice = $this->offer->price_minor - 50000;
        Offer::query()->whereKey($this->offer->id)->update(['price_minor' => $newPrice]);

        $this->postJson("/api/orders/{$id}/reprice", ['expected_price_minor' => $newPrice])
            ->assertOk()
            ->assertJsonPath('amount_minor', $newPrice)
            ->assertJsonPath('discount_minor', (int) round($newPrice * 0.10))
            ->assertJsonPath('total_minor', $newPrice - (int) round($newPrice * 0.10));

        // Слот промокода — тот же самый, что и при подорожании: направление
        // изменения цены не влияет на то, что слот один и не перевыделяется.
        $this->assertSame(1, PromoRedemption::query()->where('order_id', $id)->count());
    }

    /**
     * A13: недостижимо через штатный API (промокод проверяется целиком при
     * создании заказа), но достижимо, если код исчезает или меняется ПОСЛЕ
     * создания заказа — тогда пересчёт скидки внутри RepriceOrder бросает
     * PromoUnavailable, а reprice() ловил только RepriceRefused и отдавал
     * 500 вместо понятного отказа.
     */
    public function test_reprice_returns_409_when_the_promo_code_became_unavailable(): void
    {
        $id = $this->postJson('/api/orders',
            ['offer_id' => $this->offer->id, 'promo_code' => 'WELCOME10'],
            ['Idempotency-Key' => 'rp-6'])->assertCreated()->json('id');

        $newPrice = $this->offer->price_minor + 10000;
        Offer::query()->whereKey($this->offer->id)->update(['price_minor' => $newPrice]);

        // Промокод стал недоступен ПОСЛЕ создания заказа — правкой строки
        // промокода напрямую, как и предписано брифом задачи. Не удаление:
        // на код уже ссылается promo_redemptions заказа (FK), удаление
        // строки упало бы нарушением ограничения. Меняем тип на 'amount' с
        // чужой валютой — discountFor() бросает currency_mismatch тем же
        // путём, каким штатно бросил бы not_found на пропавшем коде.
        Promocode::query()->whereKey('WELCOME10')->update(['type' => 'amount', 'currency' => 'USD']);

        $this->postJson("/api/orders/{$id}/reprice", ['expected_price_minor' => $newPrice])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'currency_mismatch')
            ->assertJsonPath('current_price_minor', $newPrice);

        // Заказ остался нетронутым: PromoUnavailable бросается ДО update()
        // внутри RepriceOrder, транзакция откатывается целиком.
        $this->assertSame($this->offer->price_minor, Order::query()->findOrFail($id)->amount_minor);
    }
}
