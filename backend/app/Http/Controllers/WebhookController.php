<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Payments\ApplyPaymentEvent;
use App\Domain\Payments\WebhookPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WebhookController extends Controller
{
    /**
     * Вебхук платёжной системы.
     *
     * Отвечает быстро и всегда 200, если запрос корректен: обработка
     * идемпотентна, дубли и нарушенный порядок — норма, а не ошибка.
     * Код 5xx возвращается только при настоящем сбое, чтобы платёжная
     * система повторила доставку.
     */
    public function handle(Request $request, ApplyPaymentEvent $apply): JsonResponse
    {
        $data = $request->validate([
            'event_id' => ['required', 'string', 'max:128'],
            'order_id' => ['required', 'string', 'max:128'],
            'status' => ['required', 'string', 'in:paid,failed'],
            'amount' => ['nullable', 'numeric'],
            'currency' => ['nullable', 'string', 'size:3'],
            'created_at' => ['nullable', 'date'],
        ]);

        $outcome = $apply(WebhookPayload::fromArray($data));

        return response()->json([
            'received' => true,
            'outcome' => $outcome->value,
        ]);
    }
}
