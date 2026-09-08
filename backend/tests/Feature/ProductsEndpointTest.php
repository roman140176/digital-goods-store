<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Offer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductsEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_products_price_is_the_cheapest_active_offer(): void
    {
        $product = collect($this->getJson('/api/products')->assertOk()->json('products'))
            ->firstWhere('sku', 'KEY-CS2-PRIME');

        $cheapest = Offer::query()->where('product_sku', 'KEY-CS2-PRIME')
            ->where('status', 'active')->orderBy('price_minor')->firstOrFail();

        // Форма ответа первого этапа сохраняется: витрина читает price_minor,
        // и колонки products.price_minor больше нет.
        $this->assertSame($cheapest->price_minor, $product['price_minor']);
        $this->assertSame('RUB', $product['currency']);
        $this->assertArrayHasKey('name', $product);
        $this->assertArrayHasKey('type', $product);
        $this->assertArrayHasKey('image', $product);
    }

    public function test_hidden_offers_do_not_set_the_price(): void
    {
        $cheapest = Offer::query()->where('product_sku', 'KEY-GTA5')
            ->where('status', 'active')->orderBy('price_minor')->firstOrFail();

        Offer::query()->whereKey($cheapest->id)->update(['status' => 'hidden']);

        $next = Offer::query()->where('product_sku', 'KEY-GTA5')
            ->where('status', 'active')->orderBy('price_minor')->firstOrFail();

        $product = collect($this->getJson('/api/products')->assertOk()->json('products'))
            ->firstWhere('sku', 'KEY-GTA5');

        $this->assertSame($next->price_minor, $product['price_minor']);
    }
}
