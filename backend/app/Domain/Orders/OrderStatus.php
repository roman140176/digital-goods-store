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

    /**
     * Абсолютно неизменяемое состояние: не отменяется ничем, включая
     * запоздавшие вебхуки. Иначе выданный ключ был бы потерян или задвоен.
     */
    public function isImmutable(): bool
    {
        return $this === self::Delivered;
    }

    /** Финальные по ТЗ состояния — заказ больше не в работе. */
    public function isFinal(): bool
    {
        return $this === self::Delivered || $this === self::PaymentFailed;
    }

    /** Оплачено, но не выдано — повторная выдача безопасна. */
    public function isRecoverable(): bool
    {
        return $this === self::OutOfStock || $this === self::DeliveryFailed;
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
        };
    }
}
