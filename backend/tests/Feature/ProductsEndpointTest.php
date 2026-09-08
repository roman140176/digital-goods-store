<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Product;
use App\Models\Seller;
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

    /**
     * Ревью после реализации. JOIN LATERAL в ProductController — намеренно
     * без LEFT: позиция без единого активного предложения обязана пропасть
     * из выдачи целиком, а не показаться с пустой ценой (показывать товар,
     * который никто не продаёт, нельзя). Без этого теста риск не защищён:
     * если кто-то позже заменит его на LEFT JOIN LATERAL, посчитав исчезновение
     * товара багом, ни один тест файла этого не заметит — оба остальных теста
     * работают со сценарием «активное предложение есть».
     */
    public function test_product_with_no_active_offers_is_absent_from_the_listing(): void
    {
        Offer::query()->where('product_sku', 'KEY-EFT')->update(['status' => 'hidden']);

        $products = collect($this->getJson('/api/products')->assertOk()->json('products'));

        $this->assertNull($products->firstWhere('sku', 'KEY-EFT'));
        $this->assertCount(11, $products, 'из 12 позиций сида пропадает ровно одна');
    }

    /**
     * ProductController.php сортирует кандидатов внутри LATERAL по
     * (price_minor, id) — при равенстве цены выбор обязан быть
     * детерминированным между запросами: без второго ключа сортировки два
     * одинаковых запроса к одним и тем же данным могли бы отдать то одного
     * продавца, то другого.
     *
     * id первому предложению назначается явно и заведомо большим — это
     * разрывает корреляцию между порядком вставки и порядком id. Без этого
     * строка с меньшим id — она же первая физически вставленная (первая в
     * heap по TID), и PostgreSQL при равенстве price_minor вернула бы именно
     * её и без сортировки по id вовсе (упорядочивая дубликаты ключа индекса
     * по TID, который совпадает с порядком вставки у двух подряд вставленных
     * строк) — тест был бы зелёным что с `, o.id`, что без него и не
     * доказывал бы ровным счётом ничего (ревью второго раунда, проверено
     * эмпирически). Явный id не двигает sequence, поэтому вторая вставка
     * получает id из неё как обычно — маленький, хотя физически вставлена
     * второй.
     *
     * currency — единственное поле в форме ответа первого этапа, которое
     * отличает два предложения с одинаковой ценой (offer_id и seller сюда
     * не попадают, см. решения задачи 4b), поэтому по нему и проверяется,
     * какое предложение выбрано.
     */
    public function test_price_tie_between_two_offers_is_broken_by_the_smaller_id(): void
    {
        Product::query()->create(['sku' => 'TIE-TEST', 'name' => 'Позиция для проверки ничьей', 'type' => 'key']);
        $seller = Seller::query()->firstOrFail();

        $later = Offer::query()->create([
            'id' => 900000, 'product_sku' => 'TIE-TEST', 'seller_id' => $seller->id, 'supplier_id' => 'b',
            'price_minor' => 100000, 'currency' => 'EUR', 'status' => 'active',
        ]);
        $earlier = Offer::query()->create([
            'product_sku' => 'TIE-TEST', 'seller_id' => $seller->id, 'supplier_id' => 'a',
            'price_minor' => 100000, 'currency' => 'RUB', 'status' => 'active',
        ]);
        $this->assertLessThan($later->id, $earlier->id, 'физически вторая вставка обязана получить меньший id');

        $product = collect($this->getJson('/api/products')->assertOk()->json('products'))
            ->firstWhere('sku', 'TIE-TEST');

        $this->assertSame(100000, $product['price_minor']);
        $this->assertSame('RUB', $product['currency']);
    }
}
