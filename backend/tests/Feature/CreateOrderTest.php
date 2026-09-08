<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Order;
use App\Models\Promocode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class CreateOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Queue::fake();
    }

    /**
     * Цена живёт в предложении, не в товаре (задача 4b): тесты на скидку
     * читают её из офера сида, а не хранят рядом свою копию числа, которая
     * могла бы разойтись с сидом молча.
     */
    private function cheapestActiveOffer(string $sku): Offer
    {
        return Offer::query()->where('product_sku', $sku)
            ->where('status', 'active')->orderBy('price_minor')->firstOrFail();
    }

    public function test_repeated_request_with_same_idempotency_key_creates_one_order(): void
    {
        $headers = ['Idempotency-Key' => 'double-click-1'];

        $first = $this->postJson('/api/orders', ['sku' => 'KEY-CS2-PRIME'], $headers);
        $second = $this->postJson('/api/orders', ['sku' => 'KEY-CS2-PRIME'], $headers);

        $first->assertCreated();
        $second->assertOk();

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, Order::query()->count());
    }

    public function test_request_without_idempotency_key_is_rejected(): void
    {
        $this->postJson('/api/orders', ['sku' => 'KEY-CS2-PRIME'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'idempotency_key_required');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_server_calculates_percent_discount(): void
    {
        $price = $this->cheapestActiveOffer('KEY-CS2-PRIME')->price_minor;

        $response = $this->postJson(
            '/api/orders',
            ['sku' => 'KEY-CS2-PRIME', 'promo_code' => 'WELCOME10'],
            ['Idempotency-Key' => 'promo-percent'],
        );

        // WELCOME10 — 10% (см. PromocodeSeeder), округление вниз, как в PromoService.
        $discount = intdiv($price * 10, 100);

        $response->assertCreated()
            ->assertJsonPath('amount_minor', $price)
            ->assertJsonPath('discount_minor', $discount)
            ->assertJsonPath('total_minor', $price - $discount);
    }

    public function test_server_calculates_fixed_discount(): void
    {
        $price = $this->cheapestActiveOffer('KEY-CS2-PRIME')->price_minor;

        $response = $this->postJson(
            '/api/orders',
            ['sku' => 'KEY-CS2-PRIME', 'promo_code' => 'GG500'],
            ['Idempotency-Key' => 'promo-amount'],
        );

        // GG500 — фиксированные 500 ₽ = 50000 копеек (см. PromocodeSeeder),
        // от цены товара не зависят.
        $response->assertCreated()
            ->assertJsonPath('discount_minor', 50000)
            ->assertJsonPath('total_minor', $price - 50000);
    }

    public function test_client_cannot_influence_price_or_discount(): void
    {
        $price = $this->cheapestActiveOffer('KEY-CS2-PRIME')->price_minor;

        $response = $this->postJson(
            '/api/orders',
            ['sku' => 'KEY-CS2-PRIME', 'amount_minor' => 1, 'discount_minor' => $price - 1, 'total_minor' => 1],
            ['Idempotency-Key' => 'price-tamper'],
        );

        $response->assertCreated()
            ->assertJsonPath('amount_minor', $price)
            ->assertJsonPath('discount_minor', 0)
            ->assertJsonPath('total_minor', $price);
    }

    public function test_double_click_with_promo_consumes_single_use(): void
    {
        $headers = ['Idempotency-Key' => 'promo-double-click'];

        $this->postJson('/api/orders', ['sku' => 'KEY-CS2-PRIME', 'promo_code' => 'LIMIT3'], $headers)->assertCreated();
        $this->postJson('/api/orders', ['sku' => 'KEY-CS2-PRIME', 'promo_code' => 'LIMIT3'], $headers)->assertOk();

        $this->assertSame(1, Promocode::query()->find('LIMIT3')?->used_count);
    }

    public function test_exhausted_promo_is_rejected(): void
    {
        $this->postJson('/api/orders', ['sku' => 'KEY-GTA5', 'promo_code' => 'ONCEONLY'], ['Idempotency-Key' => 'once-1'])
            ->assertCreated();

        $this->postJson('/api/orders', ['sku' => 'KEY-GTA5', 'promo_code' => 'ONCEONLY'], ['Idempotency-Key' => 'once-2'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'limit_reached');

        $this->assertSame(1, Promocode::query()->find('ONCEONLY')?->used_count);
    }

    public function test_unknown_sku_is_rejected(): void
    {
        $this->postJson('/api/orders', ['sku' => 'NO-SUCH-SKU'], ['Idempotency-Key' => 'unknown-sku'])
            ->assertStatus(422);
    }

    public function test_too_long_idempotency_key_is_rejected_with_a_reason(): void
    {
        // Колонка varchar(255): без проверки запрос падал ошибкой вставки.
        $this->postJson('/api/orders', ['sku' => 'KEY-GTA5'], ['Idempotency-Key' => str_repeat('k', 256)])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'idempotency_key_too_long');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_api_errors_are_json_even_without_accept_header(): void
    {
        // Без этого ошибка валидации уходила редиректом на HTML-витрину.
        $this->post('/api/orders', [], ['Idempotency-Key' => 'no-accept-header'])
            ->assertStatus(422)
            ->assertHeader('content-type', 'application/json');
    }

    public function test_unknown_order_returns_short_json_404(): void
    {
        $this->getJson('/api/orders/ord_nope')
            ->assertStatus(404)
            ->assertJsonPath('reason', 'not_found')
            ->assertJsonMissingPath('trace');
    }
}
