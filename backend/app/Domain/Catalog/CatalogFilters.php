<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use Illuminate\Http\Request;

/**
 * Нормализованные параметры каталога — сырые query-параметры превращаются в
 * проверенные, ограниченные значения, с которыми дальше работает
 * CatalogQuery.
 *
 * Некорректный ввод не превращается в 422: листинг — это GET с фильтрами,
 * который вводится по мере набора в поисковой строке (5.1 спеки), а не форма
 * с кнопкой «отправить». Отдать ошибку на «сломанный» промежуточный ввод
 * (лишний символ в sort, отрицательная страница, per_page=100000) значило бы
 * мигать пользователю ошибкой вместо каталога. Здесь та же идея, что и guard
 * clauses в остальном проекте, только цель — не отказ, а безопасный дефолт.
 */
final readonly class CatalogFilters
{
    /**
     * Жёсткий потолок страницы. Без него per_page=100000 положил бы отдачу —
     * это не гипотеза, а первое, что делает любой любопытный проверяющий.
     */
    public const MAX_PER_PAGE = 48;

    public const DEFAULT_PER_PAGE = 24;

    /**
     * Триграммы короче трёх символов индекс gin_trgm_ops не использует
     * (ограничение pg_trgm) — на них поиск деградирует в полный перебор.
     * Порог на уровне API всё равно 2, а не 3 (см. бриф задачи 8): запрос
     * из одного символа не ищет вовсе и отдаёт каталог целиком, а не
     * пытается фильтровать по почти каждому названию подряд.
     */
    public const MIN_QUERY_LENGTH = 2;

    /** @var list<string> */
    public const SORTS = ['price_asc', 'price_desc', 'name'];

    /**
     * Сортировка первого этапа — по sku, а не по цене. Не входит в SORTS:
     * это деталь контракта конкретно ProductController (см. unrestricted()
     * и его вызов), а не документированный режим публичного /api/catalog,
     * который эту строку никогда не должен принять через ?sort=.
     */
    public const SORT_SKU = 'sku';

    private function __construct(
        public ?string $q,
        public ?string $type,
        public ?int $priceMin,
        public ?int $priceMax,
        public bool $inStock,
        public ?int $sellerId,
        public string $sort,
        public int $page,
        public int $perPage,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            q: self::normalizedQuery($request),
            type: self::nullableString($request, 'type'),
            priceMin: self::nullableNonNegativeInt($request, 'price_min'),
            priceMax: self::nullableNonNegativeInt($request, 'price_max'),
            inStock: $request->boolean('in_stock'),
            sellerId: self::nullableNonNegativeInt($request, 'seller'),
            sort: self::normalizedSort($request),
            page: self::normalizedPage($request),
            perPage: self::normalizedPerPage($request),
        );
    }

    /**
     * Весь активный каталог, без q/типа/цены/продавца — читает
     * ProductController ради формы ответа первого этапа (см. решения задачи
     * 8): раньше пагинации не было вовсе, а без верхнего предела per_page
     * отдавать тоже нельзя, поэтому предел — тот же MAX_PER_PAGE, что и у
     * публичного каталога.
     *
     * $sort — обязательный параметр без дефолта намеренно: у вызывающего
     * кода ровно один потребитель (ProductController), и он обязан явно
     * назвать SORT_SKU в точке вызова, а не унаследовать какой-то дефолт
     * молча — так решение «эта ручка сортирует иначe, чем /api/catalog»
     * видно там, где его действительно принимают.
     */
    public static function unrestricted(string $sort): self
    {
        return new self(
            q: null,
            type: null,
            priceMin: null,
            priceMax: null,
            inStock: false,
            sellerId: null,
            sort: $sort,
            page: 1,
            perPage: self::MAX_PER_PAGE,
        );
    }

    private static function normalizedQuery(Request $request): ?string
    {
        $q = trim((string) $request->query('q', ''));

        return mb_strlen($q) < self::MIN_QUERY_LENGTH ? null : $q;
    }

    private static function normalizedSort(Request $request): string
    {
        $sort = (string) $request->query('sort', 'price_asc');

        return in_array($sort, self::SORTS, true) ? $sort : 'price_asc';
    }

    private static function normalizedPage(Request $request): int
    {
        return max(1, (int) $request->query('page', 1));
    }

    private static function normalizedPerPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);

        return min(max(1, $perPage), self::MAX_PER_PAGE);
    }

    private static function nullableString(Request $request, string $key): ?string
    {
        $value = trim((string) $request->query($key, ''));

        return $value === '' ? null : $value;
    }

    /**
     * Нечисловой или отрицательный ввод молча игнорируется (фильтр не
     * применяется), а не превращается в ошибку: price_min=abc — не повод
     * прервать поиск, который человек ещё, возможно, не закончил вводить.
     */
    private static function nullableNonNegativeInt(Request $request, string $key): ?int
    {
        $value = $request->query($key);

        if ($value === null || $value === '' || ! is_numeric($value) || (int) $value < 0) {
            return null;
        }

        return (int) $value;
    }
}
