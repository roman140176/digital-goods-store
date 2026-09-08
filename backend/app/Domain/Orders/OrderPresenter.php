<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Realtime\EventBus;
use App\Domain\Realtime\OfferState;
use App\Domain\Stock\StockService;
use App\Models\Order;
use App\Models\StockUnit;

/**
 * Единая форма заказа для API и для потока событий (см. решения задачи 4a:
 * EventBus::publishOrder отдаёт ровно этот же массив). Одна форма означает,
 * что клиент применяет событие как снапшот целиком, а не мержит поля из
 * разных источников. Деньги — в копейках.
 */
final class OrderPresenter
{
    /** @return array<string, mixed> */
    public static function toArray(Order $order): array
    {
        $order->loadMissing(['product', 'delivery', 'audit']);

        $unit = (new StockService)->unitFor($order->id);
        $offer = $order->offer_id === null ? null : OfferState::forOffer((int) $order->offer_id);

        return [
            'id' => $order->id,
            'sku' => $order->sku,
            'name' => $order->product?->name,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'is_final' => $order->status->isFinal(),
            'is_recoverable' => $order->status->isRecoverable(),
            'offer_id' => $order->offer_id === null ? null : (int) $order->offer_id,
            'amount_minor' => $order->amount_minor,
            'discount_minor' => $order->discount_minor,
            'total_minor' => $order->total_minor,
            'currency' => $order->currency,
            'promo_code' => $order->promo_code,
            'code' => $order->delivered_code,
            'delivered_by' => $order->delivered_by,
            'delivered_at' => $order->delivered_at?->toIso8601String(),
            'created_at' => $order->created_at?->toIso8601String(),
            'refund_required' => (bool) $order->refund_required,
            'offer' => $offer?->toArray(),
            'reservation' => self::reservation($unit),
            'stream_cursor' => (new EventBus)->cursor(),
            'delivery' => $order->delivery === null ? null : [
                'state' => $order->delivery->state->value,
                'attempts' => $order->delivery->attempts,
                'supplier' => $order->delivery->supplier,
                'last_error' => $order->delivery->last_error,
            ],
            'history' => $order->audit
                ->sortBy('id')
                ->map(fn ($row) => [
                    'action' => $row->action,
                    'from' => $row->from_state,
                    'to' => $row->to_state,
                    'actor' => $row->actor,
                    'at' => $row->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * null, если у заказа сейчас нет активной брони с дедлайном: либо
     * единицы вообще нет (старые заказы без offer_id, см. решения задачи
     * 4a — колонка ещё nullable), либо она уже продана (reserved_until
     * стирается продажей, см. 3.1 спеки, — отсчёту после оплаты полагается
     * ни на что не влиять, 3.3 ТЗ).
     *
     * @return array{unit_id: int, expires_at: string, seconds_left: int}|null
     */
    private static function reservation(?StockUnit $unit): ?array
    {
        if ($unit === null || $unit->reserved_until === null) {
            return null;
        }

        return [
            'unit_id' => $unit->id,
            'expires_at' => $unit->reserved_until->toIso8601String(),
            // Секунды считаются от текущего момента запроса, а не хранятся
            // отдельно: одна и та же бронь у двух запросов подряд обязана
            // показать честно убывающий остаток, а не «замороженное» число.
            // max(0, ...) — не отрицательное даже если ответ уехал на пару
            // секунд позже вычисления дедлайна.
            'seconds_left' => max(0, $unit->reserved_until->getTimestamp() - now()->getTimestamp()),
        ];
    }
}
