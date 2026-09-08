<?php

declare(strict_types=1);

namespace App\Domain\Orders;

/**
 * Жизненный цикл заказа из ТЗ.
 *
 * Основной путь: created → paid → delivering → delivered.
 * Ветки сбоев: created → payment_failed;
 *              paid → delivering → out_of_stock → (после пополнения) delivered;
 *              paid → delivering → delivery_failed → (повтор) delivered.
 * Бронь: created → reservation_expired → (поздняя оплата всё ещё принимается,
 *        см. 6.4 спеки второго этапа) paid.
 */
enum OrderStatus: string
{
    case Created = 'created';
    case Paid = 'paid';
    case Delivering = 'delivering';
    case Delivered = 'delivered';
    case PaymentFailed = 'payment_failed';
    case OutOfStock = 'out_of_stock';
    case DeliveryFailed = 'delivery_failed';
    case ReservationExpired = 'reservation_expired';

    /**
     * Абсолютно неизменяемое состояние: не отменяется ничем, включая
     * запоздавшие вебхуки. Иначе выданный ключ был бы потерян или задвоен.
     */
    public function isImmutable(): bool
    {
        return $this === self::Delivered;
    }

    /**
     * Финальные по ТЗ состояния — заказ больше не в работе. ReservationExpired
     * тоже финален для опроса статуса, но не для оплаты: см. acceptsPayment().
     */
    public function isFinal(): bool
    {
        return $this === self::Delivered
            || $this === self::PaymentFailed
            || $this === self::ReservationExpired;
    }

    /** Оплачено, но не выдано — повторная выдача безопасна. */
    public function isRecoverable(): bool
    {
        return $this === self::OutOfStock || $this === self::DeliveryFailed;
    }

    /** Из этих состояний оплата ещё может быть применена (см. 6.4 спеки). */
    public function acceptsPayment(): bool
    {
        return $this === self::Created
            || $this === self::PaymentFailed
            || $this === self::ReservationExpired;
    }

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Ожидает оплаты',
            self::Paid => 'Оплачен',
            self::Delivering => 'Идёт выдача',
            self::Delivered => 'Код выдан',
            self::PaymentFailed => 'Оплата не прошла',
            self::OutOfStock => 'Нет в наличии, ожидает пополнения',
            self::DeliveryFailed => 'Ошибка выдачи, будет повторена',
            self::ReservationExpired => 'Бронь истекла',
        };
    }
}
