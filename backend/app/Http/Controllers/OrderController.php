<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Orders\CreateOrder;
use App\Domain\Orders\OrderPresenter;
use App\Domain\Orders\RepriceOrder;
use App\Domain\Orders\RepriceRefused;
use App\Domain\Promo\PromoUnavailable;
use App\Domain\Stock\SoldOut;
use App\Models\Offer;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrderController extends Controller
{
    public function store(Request $request, CreateOrder $createOrder): JsonResponse
    {
        $data = $request->validate([
            // offer_id — основной путь; sku без него принимается ради
            // совместимости с кнопкой пополнения Steam первого этапа (см.
            // решения задачи 4a). required_without с обеих сторон даёт
            // стандартную 422-ошибку Laravel, если не пришло ни одного поля.
            'offer_id' => ['nullable', 'integer', 'required_without:sku', 'exists:offers,id'],
            'sku' => ['nullable', 'string', 'required_without:offer_id', 'exists:products,sku'],
            'promo_code' => ['nullable', 'string', 'max:64'],
        ]);

        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));

        // Без ключа идемпотентности гарантию «один клик — один заказ» дать
        // нельзя, поэтому запрос не принимается вовсе.
        if ($idempotencyKey === '') {
            return response()->json([
                'message' => 'Требуется заголовок Idempotency-Key.',
                'reason' => 'idempotency_key_required',
            ], 422);
        }

        // Ключ уходит в колонку varchar(255): без этой проверки слишком длинный
        // заголовок падал бы ошибкой вставки, а не понятным ответом.
        if (mb_strlen($idempotencyKey) > 255) {
            return response()->json([
                'message' => 'Заголовок Idempotency-Key длиннее 255 символов.',
                'reason' => 'idempotency_key_too_long',
            ], 422);
        }

        try {
            $result = $createOrder(
                $data['sku'] ?? null,
                $data['promo_code'] ?? null,
                $idempotencyKey,
                offerId: isset($data['offer_id']) ? (int) $data['offer_id'] : null,
            );
        } catch (PromoUnavailable $e) {
            return response()->json([
                'message' => match ($e->why) {
                    'limit_reached' => 'Промокод исчерпал лимит использований.',
                    'currency_mismatch' => 'Промокод не подходит по валюте.',
                    default => 'Промокод не найден.',
                },
                'reason' => $e->why,
                'promo_code' => $e->promoCode,
            ], 422);
        } catch (SoldOut $e) {
            // Понятное сообщение вместо тупика: предлагаем следующее по цене
            // активное предложение той же позиции со свободной единицей
            // (требование 2.2 ТЗ). Технические подробности (offer_id
            // раскупленного предложения) наружу не идут — покупателю они
            // не нужны, sku и alternative достаточно, чтобы решить, что делать.
            return response()->json([
                'message' => 'Товар только что раскупили.',
                'reason' => 'sold_out',
                'alternative' => $e->alternative?->toArray(),
                'product_sku' => $e->sku,
            ], 409);
        }

        return response()->json(
            OrderPresenter::toArray($result['order']),
            $result['created'] ? 201 : 200,
        );
    }

    public function show(Order $order): JsonResponse
    {
        return response()->json(OrderPresenter::toArray($order));
    }

    /**
     * Принятие изменившейся цены предложения до оплаты (требование 1.3 ТЗ,
     * 6.6 спеки). Серверный гард в /api/dev/pay уже отказывает платить по
     * устаревшей цене — эта ручка даёт покупателю способ согласиться на
     * новую цену и продолжить, а не только упереться в отказ.
     */
    public function reprice(Request $request, Order $order, RepriceOrder $repriceOrder): JsonResponse
    {
        $data = $request->validate([
            'expected_price_minor' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $order = $repriceOrder($order, (int) $data['expected_price_minor']);
        } catch (RepriceRefused $e) {
            return response()->json([
                'message' => match ($e->reason) {
                    'price_changed' => 'Цена предложения снова изменилась, подтвердите актуальную цену.',
                    default => 'Заказ уже нельзя перерасценить.',
                },
                'reason' => $e->reason,
                'current_price_minor' => $e->currentPriceMinor,
            ], 409);
        } catch (PromoUnavailable $e) {
            // Недостижимо через штатный API (промокод проверяется при
            // создании заказа), но достижимо, если код исчез или изменился
            // ПОСЛЕ создания заказа: RepriceOrder пересчитывает скидку через
            // PromoService::discountFor() и там же может напороться на уже
            // недоступный код. Тот же формат 409, что и у ветки
            // RepriceRefused выше — тому же покупателю тот же незавершённый
            // reprice, отказ обязан быть понятным, а не 500.
            return response()->json([
                'message' => match ($e->why) {
                    'currency_mismatch' => 'Промокод не подходит по валюте.',
                    default => 'Промокод не найден.',
                },
                'reason' => $e->why,
                'current_price_minor' => (int) Offer::query()->whereKey($order->offer_id)->value('price_minor'),
            ], 409);
        }

        return response()->json(OrderPresenter::toArray($order));
    }
}
