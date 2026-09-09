<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use Illuminate\Support\Facades\DB;

/**
 * Поиск и фильтры каталога: одна позиция — одно лучшее (самое дешёвое)
 * активное предложение на JOIN LATERAL, вокруг него — фильтры, сортировка и
 * пагинация. Тот же приём, что уже показал себя в ProductController первого
 * этапа (задача 4b, см. его докблок): JOIN LATERAL без LEFT — позиция без
 * единого активного предложения не должна попасть в выдачу вовсе, показывать
 * товар, который никто не продаёт, нельзя. Кандидат внутри LATERAL
 * сортируется по (price_minor, id): при равенстве цены двух предложений
 * позиции результат обязан быть детерминированным, иначе от повторного
 * запроса к тем же данным можно получить два разных «лучших» предложения
 * (ProductControllerTest уже проверяет ровно эту гарантию, ломать нельзя).
 *
 * available считает физически свободные единицы ИЛИ чужую просроченную
 * бронь — то же условие, каким StockService::reserve() прямо сейчас может
 * захватить единицу (6.1 спеки) и каким её же считает OfferState. Если бы
 * список каталога считал иначе, он показывал бы товар недоступным до тика
 * планировщика ReleaseExpiredReservations — то расхождение с реальностью,
 * которое требование 1.2 ТЗ запрещает.
 *
 * Страница позиций и total считаются ОДНИМ оператором SQL, а не двумя
 * отдельными запросами: общий CTE filtered (WHERE и LATERAL — buildWhere() и
 * bestOfferLateral(), переиспользуются буквально) материализуется, потому
 * что на него ссылаются дважды — вложенный CTE страницы (ORDER BY/LIMIT/
 * OFFSET) и count(*) для total, — и Postgres считает саму выборку один раз
 * за весь запрос, а не дважды. Один снимок вместо двух отдельных запросов
 * важен не только производительностью: под конкурентной записью (админские
 * правки, тик планировщика) два независимых запроса рисковали увидеть два
 * разных среза данных, и total мог разойтись со списком. total обязан
 * значить «сколько позиций прошло ровно тот же фильтр», а не приближение —
 * иначе на фильтре по цене/наличию/продавцу total разошёлся бы со списком.
 * Каталог здесь — тысячи предложений, а не миллион (допущение 12.5 спеки:
 * count(*) по всему каталогу — первое, во что упёрлась бы схема при
 * миллионе позиций, и это сознательно оставлено на потом).
 */
final class CatalogQuery
{
    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(CatalogFilters $filters): array
    {
        [$where, $bindings] = $this->buildWhere($filters);
        $order = $this->buildOrder($filters->sort);
        $offset = ($filters->page - 1) * $filters->perPage;
        $lateral = $this->bestOfferLateral();

        // filtered ссылается на себя дважды ниже (count(*) и CTE страницы),
        // поэтому Postgres материализует её по умолчанию — сама выборка с
        // LATERAL считается один раз за весь оператор. MATERIALIZED здесь не
        // намёк планировщику, а гарантия: без неё total и items рисковали бы
        // разойтись, будь materialize-эвристика когда-нибудь другой.
        // COALESCE на items — json_agg по нулю строк (страница за пределами
        // данных) возвращает SQL NULL, а не пустой массив.
        $row = DB::selectOne(
            "WITH filtered AS MATERIALIZED (
                    SELECT p.sku, p.name, p.type, p.image,
                           b.offer_id, b.price_minor, b.currency, b.seller_id, b.seller_name,
                           b.available, b.offers_count
                      FROM products p
                      JOIN LATERAL ({$lateral}) b ON true
                     WHERE {$where}
                 ),
                 page AS (
                    SELECT *
                      FROM filtered
                     ORDER BY {$order}
                     LIMIT ? OFFSET ?
                 )
             SELECT (SELECT count(*) FROM filtered) AS total,
                    COALESCE((SELECT json_agg(page ORDER BY {$order}) FROM page), '[]') AS items",
            [...$bindings, $filters->perPage, $offset],
        );

        // json_agg отдаёт числа JSON-числами — json_decode() возвращает их
        // PHP int/float, не строками (проверено эмпирически на этой схеме);
        // mapRow() всё равно приводит числовые поля явно, как и раньше.
        return [
            'items' => array_map(self::mapRow(...), json_decode($row->items)),
            'total' => (int) $row->total,
        ];
    }

    /**
     * Лучшее предложение позиции: активное, дешевле остальных, при равенстве
     * цены — с наименьшим id. offers_count считает все активные предложения
     * позиции (не только "показанное" b), available — только у самого
     * лучшего, ради которого LATERAL и существует.
     */
    private function bestOfferLateral(): string
    {
        return <<<'SQL'
            SELECT o.id AS offer_id, o.price_minor, o.currency, o.seller_id,
                   s.name AS seller_name,
                   (SELECT count(*) FROM stock_units u
                     WHERE u.offer_id = o.id
                       AND (u.state = 'available'
                            OR (u.state = 'reserved' AND u.reserved_until <= now()))) AS available,
                   (SELECT count(*) FROM offers o2
                     WHERE o2.product_sku = p.sku AND o2.status = 'active') AS offers_count
              FROM offers o
              JOIN sellers s ON s.id = o.seller_id
             WHERE o.product_sku = p.sku AND o.status = 'active'
             ORDER BY o.price_minor, o.id
             LIMIT 1
            SQL;
    }

    /**
     * Условия собираются динамически, а не фиксированной цепочкой вида
     * `(:price_min IS NULL OR b.price_minor >= :price_min)`: отсутствующий
     * фильтр здесь не попадает в SQL вовсе, а не превращается в OR-ветку с
     * NULL-параметром — тот же результат, но планировщику не нужно на
     * каждый вызов разбирать условие, которое всегда истинно.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildWhere(CatalogFilters $filters): array
    {
        $conditions = [];
        $bindings = [];

        if ($filters->q !== null) {
            $conditions[] = 'p.name ILIKE ?';
            $bindings[] = '%'.self::escapeLikeWildcards($filters->q).'%';
        }

        if ($filters->type !== null) {
            $conditions[] = 'p.type = ?';
            $bindings[] = $filters->type;
        }

        if ($filters->priceMin !== null) {
            $conditions[] = 'b.price_minor >= ?';
            $bindings[] = $filters->priceMin;
        }

        if ($filters->priceMax !== null) {
            $conditions[] = 'b.price_minor <= ?';
            $bindings[] = $filters->priceMax;
        }

        if ($filters->inStock) {
            $conditions[] = 'b.available > 0';
        }

        if ($filters->sellerId !== null) {
            // По продавцу ЛУЧШЕГО предложения (b), а не по любому предложению
            // позиции: карточка каталога показывает одну цену, и «этот
            // продавец продаёт позицию» на ней означает «его предложение
            // сейчас показано как лучшее», а не «где-то у позиции есть его
            // более дорогое предложение».
            $conditions[] = 'b.seller_id = ?';
            $bindings[] = $filters->sellerId;
        }

        return [$conditions === [] ? '1 = 1' : implode(' AND ', $conditions), $bindings];
    }

    /**
     * Без табличных префиксов: результат используется дважды за пределами
     * filtered/page (ORDER BY страницы и ORDER BY внутри json_agg), а там
     * видны только имена колонок CTE (sku, name, price_minor, ...), не
     * псевдонимы products/offers p/b из bestOfferLateral() и buildWhere().
     */
    private function buildOrder(string $sort): string
    {
        // sku вторым ключом всегда (кроме самого режима 'sku', где он и так
        // первый и единственный — sku уникален сам по себе): без вторичного
        // ключа две позиции с одинаковой ценой лучшего предложения (или
        // одинаковым именем) могли бы менять порядок между total и страницей,
        // а на соседних страницах — теряться или задваиваться. Та же причина,
        // по которой LATERAL выше сортируется по (price_minor, id).
        //
        // 'sku' — режим ProductController (CatalogFilters::SORT_SKU), не
        // часть публичного whitelist SORTS: /api/catalog его не отдаёт.
        return match ($sort) {
            'price_desc' => 'price_minor DESC, sku',
            'name' => 'name, sku',
            CatalogFilters::SORT_SKU => 'sku',
            default => 'price_minor, sku',
        };
    }

    /**
     * '\' — escape-символ ILIKE по умолчанию, поэтому буквальные % и _ во
     * введённой строке (например, кто-то ищет ключ с "%" в названии)
     * экранируются под тот же символ, а не через ESCAPE с каким-то другим:
     * тогда SQL-текст запроса остаётся ровно таким, каким дан в брифе задачи
     * 8 (`ILIKE '%' || ? || '%'`), меняется только связанное значение.
     */
    private static function escapeLikeWildcards(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /** @return array<string, mixed> */
    private static function mapRow(object $row): array
    {
        return [
            'sku' => $row->sku,
            'name' => $row->name,
            'type' => $row->type,
            'image' => $row->image,
            'offers_count' => (int) $row->offers_count,
            'best' => [
                'offer_id' => (int) $row->offer_id,
                'price_minor' => (int) $row->price_minor,
                'currency' => $row->currency,
                'available' => (int) $row->available,
                'seller' => [
                    'id' => (int) $row->seller_id,
                    'name' => $row->seller_name,
                ],
            ],
        ];
    }
}
