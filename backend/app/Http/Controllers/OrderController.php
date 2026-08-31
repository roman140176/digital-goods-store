<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Orders\CreateOrder;
use App\Domain\Orders\OrderPresenter;
use App\Domain\Promo\PromoUnavailable;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrderController extends Controller
{
    public function store(Request $request, CreateOrder $createOrder): JsonResponse
    {
        $data = $request->validate([
            'sku' => ['required', 'string', 'exists:products,sku'],
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
            $result = $createOrder($data['sku'], $data['promo_code'] ?? null, $idempotencyKey);
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
}
