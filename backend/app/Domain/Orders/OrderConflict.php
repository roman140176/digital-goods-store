<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use RuntimeException;

/**
 * Вставка заказа конфликтует не по ключу идемпотентности, и вернуть уже
 * существующий заказ нельзя. Достижимо только служебной ручкой с заданным id.
 */
final class OrderConflict extends RuntimeException
{
    public function __construct(public readonly string $idempotencyKey)
    {
        parent::__construct("Заказ по ключу идемпотентности {$idempotencyKey} создать не удалось: конфликт идентификаторов.");
    }
}
