<?php

declare(strict_types=1);

namespace App\Domain\Promo;

use RuntimeException;

/** Промокода нет, он не подходит по валюте или его лимит исчерпан. */
final class PromoUnavailable extends RuntimeException
{
    public function __construct(
        public readonly string $promoCode,
        public readonly string $why,
    ) {
        parent::__construct("promo {$promoCode} unavailable: {$why}");
    }
}
