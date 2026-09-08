<?php

declare(strict_types=1);

namespace App\Domain\Stock;

use App\Domain\Realtime\OfferState;
use RuntimeException;

/**
 * Единиц предложения не осталось прямо сейчас: захват (см. 6.1 спеки) не
 * нашёл под ним ни свободной единицы, ни просроченной чужой брони.
 *
 * Несёт альтернативу — следующее по цене активное предложение той же
 * позиции со свободной единицей, — потому что отказ покупателю обязан быть
 * не тупиком, а предложением другого продавца (требование 2.2 ТЗ).
 * `alternative` пуст, если и правда нет ничего похожего на замену.
 */
final class SoldOut extends RuntimeException
{
    public function __construct(
        public readonly string $sku,
        public readonly int $offerId,
        public readonly ?OfferState $alternative,
    ) {
        parent::__construct("Единиц предложения {$offerId} товара {$sku} не осталось.");
    }
}
