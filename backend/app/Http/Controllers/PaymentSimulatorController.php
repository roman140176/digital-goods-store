<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Эмулятор платёжной системы. Реального эквайринга нет — эта ручка
 * формирует событие по контракту из ТЗ и отправляет его на наш же вебхук.
 */
final class PaymentSimulatorController extends Controller
{
    public function pay(Request $request, Order $order): JsonResponse
    {
        $failed = $request->query('result') === 'fail';

        $payload = [
            'event_id' => 'evt_'.strtolower((string) Str::ulid()),
            'order_id' => $order->id,
            'status' => $failed ? 'failed' : 'paid',
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
