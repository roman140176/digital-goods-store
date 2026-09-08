<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Offer;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Эмулятор платёжной системы. Реального эквайринга нет — эта ручка
 * формирует событие по контракту из ТЗ и отправляет его на наш же вебхук.
 */
final class PaymentSimulatorController extends Controller
{
    public function pay(Request $request, Order $order): JsonResponse
    {
        $failed = $request->query('result') === 'fail';

        // Гард цены живёт на сервере, а не в честности клиента (требование
        // 1.3 ТЗ): пока цена предложения расходится с суммой, зафиксированной
        // при брони, оплата не уходит вовсе. Отказ не касается неуспешной
        // оплаты — заказ, которому и так предстоит не оплатиться, не нужно
        // блокировать чужой ценой.
        if (! $failed) {
            $current = (int) Offer::query()->whereKey($order->offer_id)->value('price_minor');

            if ($current !== $order->amount_minor) {
                return response()->json([
                    'message' => 'Цена предложения изменилась, подтвердите новую цену.',
                    'reason' => 'price_changed',
                    'current_price_minor' => $current,
                    'order_price_minor' => $order->amount_minor,
                ], 409);
            }
        }

        // Ключ детерминирован из id заказа, исхода и номера попытки клиента
        // (по умолчанию 1): повтор после обрыва связи обязан прислать тот
        // же event_id, а не породить второе событие платежа (требование
        // 4.1 ТЗ) — идемпотентность держит первичный ключ журнала, а не
        // ветвление по статусу заказа.
        $attempt = max(1, (int) $request->query('attempt', 1));
        $result = $failed ? 'failed' : 'paid';

        $payload = [
            'event_id' => sprintf('evt_%s_%s_%d', $order->id, $result, $attempt),
            'order_id' => $order->id,
            'status' => $result,
            'amount' => $order->total_minor / 100,
            'currency' => $order->currency,
            'created_at' => now()->toIso8601ZuluString(),
        ];

        $response = Http::timeout(10)->asJson()->post(config('store.payment_webhook_url'), $payload);

        return response()->json([
            'sent' => $payload,
            'webhook_status' => $response->status(),
            'webhook_body' => $response->json(),
        ]);
    }
}
