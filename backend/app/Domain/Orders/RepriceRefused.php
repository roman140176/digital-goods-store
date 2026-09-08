<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use RuntimeException;

/**
 * Заказ нельзя перерасценить: либо он не в статусе, из которого reprice
 * вообще допустим (оплачен, бронь истекла, идёт выдача или код уже выдан —
 * допустим только `created`), либо ожидание клиента (expected_price_minor)
 * само устарело — цена предложения успела измениться ещё раз между тем, как
 * клиент её увидел, и этим запросом.
 */
final class RepriceRefused extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly ?int $currentPriceMinor = null,
    ) {
        parent::__construct("reprice refused: {$reason}");
    }
}
