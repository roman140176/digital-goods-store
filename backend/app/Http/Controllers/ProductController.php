<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Catalog\CatalogFilters;
use App\Domain\Catalog\CatalogQuery;
use App\Domain\Realtime\EventBus;
use Illuminate\Http\JsonResponse;

final class ProductController extends Controller
{
    /**
     * Форма ответа первого этапа сохраняется ради совместимости с уже
     * собранной витриной (см. решения задачи 4b): она читает price_minor и
     * currency у позиции верхнего уровня, а колонки products.price_minor
     * больше нет. price_minor здесь — цена лучшего (самого дешёвого)
     * активного предложения позиции.
     *
     * С задачи 8 сама выборка — общий CatalogQuery, тот же движок, что у
     * /api/catalog (JOIN LATERAL на лучшее предложение, без LEFT — позиция
     * без единого активного предложения не должна попасть в выдачу вовсе,
     * см. ProductsEndpointTest::test_product_with_no_active_offers_is_absent_from_the_listing).
     * CatalogFilters::unrestricted() — весь активный каталог без q/типа/
     * цены/продавца, ограниченный только верхним пределом страницы (per_page
     * = MAX_PER_PAGE): без него per_page был бы не ограничен вовсе, а
     * убирать ограничение специально для этой ручки нет причины — у первого
     * этапа было 12 позиций, у объёмного каталога (make seed-catalog)
     * ограничение уже часть контракта задачи 8.
     *
     * Сортировка — SORT_SKU (алфавит по sku), а НЕ дефолт price_asc, каким
     * сортирует /api/catalog: первый этап отдавал по sku, и главная
     * витрина режет этот ответ на ряды карточек срезами по смещению
     * (frontend/src/main.ts, rowOf()) — порядок здесь часть уже сданного
     * контракта первого этапа, который эта задача не переписывает, а не
     * просто «какой-то» порядок. У /api/catalog сортировка по цене
     * специфицирована отдельно (5.1 спеки) — это другая ручка с другим
     * контрактом, совпадать они не обязаны.
     *
     * Поверх старой формы добавлены best (полный объект лучшего предложения,
     * тот же, что в /api/catalog) и stream_cursor, прочитанный до выборки —
     * так же, как в CatalogController::index (4.5 спеки).
     */
    public function index(CatalogQuery $catalogQuery): JsonResponse
    {
        $streamCursor = (new EventBus)->cursor();

        $result = $catalogQuery->search(CatalogFilters::unrestricted(CatalogFilters::SORT_SKU));

        return response()->json([
            'products' => array_map(static fn (array $item): array => [
                'sku' => $item['sku'],
                'name' => $item['name'],
                'type' => $item['type'],
                'price_minor' => $item['best']['price_minor'],
                'currency' => $item['best']['currency'],
                'image' => $item['image'],
                'best' => $item['best'],
            ], $result['items']),
            'stream_cursor' => $streamCursor,
        ]);
    }
}
