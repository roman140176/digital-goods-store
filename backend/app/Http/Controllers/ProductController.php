<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class ProductController extends Controller
{
    /**
     * Форма ответа первого этапа сохраняется ради совместимости с уже
     * собранной витриной (см. решения задачи 4b): она читает price_minor
     * у позиции, а колонки products.price_minor больше нет. price_minor
     * здесь — цена лучшего (самого дешёвого) активного предложения позиции,
     * посчитанная запросом, а не хранимое значение.
     *
     * JOIN LATERAL без LEFT: позиция без единого активного предложения не
     * должна попасть в выдачу вовсе — показывать товар, который никто не
     * продаёт, нельзя (ему нечего подставить в price_minor и currency).
     *
     * Сортировка внутри LATERAL — price_minor, затем id: при равенстве цен
     * у двух предложений позиции результат обязан быть детерминированным
     * (иначе от повторного запроса к тем же данным можно было получить два
     * разных "лучших" предложения).
     */
    public function index(): JsonResponse
    {
        $products = DB::select(
            "SELECT p.sku, p.name, p.type, p.image, b.price_minor, b.currency
               FROM products p
               JOIN LATERAL (
                 SELECT o.price_minor, o.currency FROM offers o
                  WHERE o.product_sku = p.sku AND o.status = 'active'
                  ORDER BY o.price_minor, o.id LIMIT 1) b ON true
              ORDER BY p.sku",
        );

        return response()->json([
            'products' => array_map(fn (object $row): array => [
                'sku' => $row->sku,
                'name' => $row->name,
                'type' => $row->type,
                'price_minor' => (int) $row->price_minor,
                'currency' => $row->currency,
                'image' => $row->image,
            ], $products),
        ]);
    }
}
