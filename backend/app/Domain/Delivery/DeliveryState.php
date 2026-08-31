<?php

declare(strict_types=1);

namespace App\Domain\Delivery;

enum DeliveryState: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Done = 'done';
    case OutOfStock = 'out_of_stock';
    case Failed = 'failed';

    /** Состояния, из которых выдачу можно безопасно перезапустить. */
    public static function claimable(): array
    {
        return [self::Pending->value, self::Failed->value, self::OutOfStock->value];
    }
}
