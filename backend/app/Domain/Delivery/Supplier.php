<?php

declare(strict_types=1);

namespace App\Domain\Delivery;

interface Supplier
{
    public function id(): string;

    /**
     * Запрос кода по контракту поставщика.
     *
     * request_id детерминирован и не меняется между попытками: поставщик
     * обязан вернуть по нему тот же самый код.
     */
    public function issue(string $requestId, string $sku, string $orderId): SupplierOutcome;

    /** Служебные ручки заглушки: нужны админке и сценариям проверки. */
    public function inventory(): array;

    public function restock(int $count): array;
}
