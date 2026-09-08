<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Product;
use App\Models\Seller;
use App\Models\StockUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CatalogSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_every_product_has_at_least_two_offers(): void
    {
        $this->assertSame(12, Product::query()->count());

        Product::query()->get()->each(function (Product $product): void {
            $this->assertGreaterThanOrEqual(
                2,
                Offer::query()->where('product_sku', $product->sku)->count(),
                "у {$product->sku} меньше двух предложений — не будет альтернативы для 2.2",
            );
        });
    }

    public function test_hot_offer_has_exactly_one_unit_and_a_pricier_alternative(): void
    {
        $hot = Offer::query()
            ->where('product_sku', 'KEY-CS2-PRIME')
            ->orderBy('price_minor')
            ->firstOrFail();

        $this->assertSame(1, StockUnit::query()
            ->where('offer_id', $hot->id)
            ->where('state', 'available')
            ->count());

        $alternative = Offer::query()
            ->where('product_sku', 'KEY-CS2-PRIME')
            ->where('id', '!=', $hot->id)
            ->where('status', 'active')
            ->orderBy('price_minor')
            ->firstOrFail();

        $this->assertGreaterThan($hot->price_minor, $alternative->price_minor);
        $this->assertGreaterThan(0, StockUnit::query()
            ->where('offer_id', $alternative->id)
            ->where('state', 'available')
            ->count());
    }

    public function test_prices_are_integers_in_minor_units(): void
    {
        // each() на пустой коллекции не выполнит колбэк ни разу и тест
        // «пройдёт» без единой проверки — явный assertGreaterThan исключает
        // такое молчаливое прохождение (см. RED-прогон: без сидов этот тест
        // падал не FAILED, а risky, именно по этой причине).
        $this->assertGreaterThan(0, Offer::query()->count());

        Offer::query()->get()->each(function (Offer $offer): void {
            $this->assertIsInt($offer->price_minor);
            $this->assertSame(0, $offer->price_minor % 100, 'цены сида — целые рубли');
        });
    }

    public function test_sellers_are_believable_and_offers_alternate_suppliers(): void
    {
        $this->assertSame(8, Seller::query()->count());

        Seller::query()->get()->each(function (Seller $seller): void {
            $this->assertGreaterThanOrEqual(4.2, (float) $seller->rating);
            $this->assertLessThanOrEqual(5.0, (float) $seller->rating);
        });

        $this->assertSame(
            ['a', 'b'],
            Offer::query()->distinct()->orderBy('supplier_id')->pluck('supplier_id')->all(),
            'supplier_id предложений должен чередоваться между обоими поставщиками',
        );
    }
}
