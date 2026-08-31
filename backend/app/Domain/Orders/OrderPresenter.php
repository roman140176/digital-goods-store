<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Models\Order;

/** Единая форма заказа для API и страницы статуса. Деньги — в копейках. */
final class OrderPresenter
{
    /** @return array<string, mixed> */
    public static function toArray(Order $order): array
    {
        $order->loadMissing(['product', 'delivery', 'audit']);

        return [
            'id' => $order->id,
            'sku' => $order->sku,
            'name' => $order->product?->name,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'is_final' => $order->status->isFinal(),
            'is_recoverable' => $order->status->isRecoverable(),
            'amount_minor' => $order->amount_minor,
            'discount_minor' => $order->discount_minor,
            'total_minor' => $order->total_minor,
            'currency' => $order->currency,
            'promo_code' => $order->promo_code,
            'code' => $order->delivered_code,
            'delivered_by' => $order->delivered_by,
            'delivered_at' => $order->delivered_at?->toIso8601String(),
            'created_at' => $order->created_at?->toIso8601String(),
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
}
