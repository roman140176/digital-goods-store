<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\StockUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CatalogSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_catalog_returns_the_cheapest_offer_per_product(): void
    {
        $response = $this->getJson('/api/catalog?per_page=50')->assertOk();

        $item = collect($response->json('items'))->firstWhere('sku', 'KEY-CS2-PRIME');

        $cheapest = Offer::query()->where('product_sku', 'KEY-CS2-PRIME')
            ->where('status', 'active')->orderBy('price_minor')->firstOrFail();

        $this->assertSame($cheapest->id, $item['best']['offer_id']);
        $this->assertSame($cheapest->price_minor, $item['best']['price_minor']);
        $this->assertGreaterThanOrEqual(2, $item['offers_count']);
        $this->assertIsInt($response->json('stream_cursor'));
    }

    public function test_search_matches_a_substring_case_insensitively(): void
    {
        $items = $this->getJson('/api/catalog?q='.urlencode('discord'))->assertOk()->json('items');

        $this->assertNotEmpty($items);
        foreach ($items as $item) {
            $this->assertStringContainsStringIgnoringCase('discord', $item['name']);
        }
    }

    public function test_filters_narrow_the_result(): void
    {
        $items = $this->getJson('/api/catalog?type=subscription&per_page=50')
            ->assertOk()->json('items');

        $this->assertNotEmpty($items);
        foreach ($items as $item) {
            $this->assertSame('subscription', $item['type']);
        }

        $cheap = $this->getJson('/api/catalog?price_max=40000&per_page=50')
            ->assertOk()->json('items');

        // Без этой проверки пустая выборка сделала бы foreach ниже пустым
        // no-op и тест зелёным независимо от того, работает фильтр или нет.
        $this->assertNotEmpty($cheap);
        foreach ($cheap as $item) {
            $this->assertLessThanOrEqual(40000, $item['best']['price_minor']);
        }
    }

    public function test_in_stock_filter_hides_products_without_a_free_unit(): void
    {
        StockUnit::query()->update(['state' => 'sold', 'sold_at' => now(),
            'reserved_order_id' => null, 'reserved_until' => null]);

        $this->assertSame(0, $this->getJson('/api/catalog?in_stock=1&per_page=50')
            ->assertOk()->json('total'));
        $this->assertGreaterThan(0, $this->getJson('/api/catalog?per_page=50')
            ->assertOk()->json('total'));
    }

    public function test_sort_and_pagination_are_stable(): void
    {
        $first = $this->getJson('/api/catalog?sort=price_asc&per_page=5&page=1')->assertOk();
        $second = $this->getJson('/api/catalog?sort=price_asc&per_page=5&page=2')->assertOk();

        $prices = fn (array $items): array => array_map(
            static fn (array $i): int => $i['best']['price_minor'], $items);

        $page1 = $prices($first->json('items'));
        $page2 = $prices($second->json('items'));

        // Без этих двух проверок пустые страницы (например, если фильтр
        // страницы сломался и всегда отдаёт []) прошли бы сравнения ниже
        // молча: assertSame([], []) истинно, а end([]) === false <= любое
        // число тоже истинно.
        $this->assertCount(5, $page1);
        $this->assertCount(5, $page2);
        $this->assertSame($page1, array_values(collect($page1)->sort()->all()));
        $this->assertLessThanOrEqual($page2[0] ?? PHP_INT_MAX, end($page1));
        $this->assertSame(12, $first->json('total'));
    }

    public function test_products_endpoint_keeps_first_stage_shape(): void
    {
        $product = collect($this->getJson('/api/products')->assertOk()->json('products'))
            ->firstWhere('sku', 'KEY-CS2-PRIME');

        // Витрина первого этапа читает price_minor: поле сохраняется и равно
        // цене лучшего предложения.
        $this->assertArrayHasKey('price_minor', $product);
        $this->assertSame($product['best']['price_minor'], $product['price_minor']);
    }

    /**
     * /api/products — единственная ручка каталога, отсортированная по sku,
     * а не по цене: первый этап резал её ответ на ряды карточек по смещению
     * (frontend/src/main.ts, rowOf()), и порядок там часть уже сданного
     * контракта, который эта задача не переписывает. /api/catalog по
     * умолчанию сортирует по цене — так специфицировано отдельно (5.1
     * спеки) — но это другая ручка с другим контрактом.
     */
    public function test_products_endpoint_is_sorted_alphabetically_by_sku(): void
    {
        $skus = collect($this->getJson('/api/products')->assertOk()->json('products'))
            ->pluck('sku')->all();

        $this->assertSame(collect($skus)->sort()->values()->all(), $skus);
    }
}
