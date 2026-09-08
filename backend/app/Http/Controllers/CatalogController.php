<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Catalog\CatalogFilters;
use App\Domain\Catalog\CatalogQuery;
use App\Domain\Realtime\EventBus;
use App\Domain\Realtime\OfferState;
use App\Models\Offer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CatalogController extends Controller
{
    /**
     * GET /api/catalog — поиск и фильтры (7 спеки). stream_cursor читается
     * ДО выборки данных (4.5 спеки): тогда возможен повтор уже применённого
     * SSE-события у клиента, но не потеря. Повтор безвреден, потому что
     * событие несёт полное состояние объекта; потеря — сломала бы живую
     * витрину (клиент решил бы, что видит актуальную цену, хотя это не так).
     */
    public function index(Request $request, CatalogQuery $catalogQuery): JsonResponse
    {
        $streamCursor = (new EventBus)->cursor();

        $filters = CatalogFilters::fromRequest($request);
        $result = $catalogQuery->search($filters);

        return response()->json([
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $filters->page,
            'per_page' => $filters->perPage,
            'stream_cursor' => $streamCursor,
        ]);
    }

    /**
     * GET /api/offers?sku=... — все активные предложения позиции с
     * остатками: нужно для альтернативы в 409 sold_out и для списка
     * продавцов на карточке товара (5.1 спеки).
     *
     * Предложений у одной позиции — единицы (2-5 по сиду), поэтому
     * по-offer-но через OfferState (как уже делает StockService::alternativeFor
     * для одного предложения) — не отдельная агрегатная выборка: та же форма
     * данных, что и у события offer.updated, без второго представления.
     */
    public function offers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sku' => ['required', 'string', 'exists:products,sku'],
        ]);

        $streamCursor = (new EventBus)->cursor();

        $offers = Offer::query()
            ->where('product_sku', $data['sku'])
            ->where('status', 'active')
            ->orderBy('price_minor')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $offerId): ?array => OfferState::forOffer((int) $offerId)?->toArray())
            // Предложение теоретически могло исчезнуть между pluck() и
            // OfferState::forOffer() (например, удалено конкурентно) —
            // null-элемент отфильтровывается, а не падает в ответ.
            ->filter()
            ->values();

        return response()->json([
            'offers' => $offers,
            'stream_cursor' => $streamCursor,
        ]);
    }
}
