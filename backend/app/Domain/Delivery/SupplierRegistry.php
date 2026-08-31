<?php

declare(strict_types=1);

namespace App\Domain\Delivery;

use InvalidArgumentException;

final readonly class SupplierRegistry
{
    /** @param array<string, Supplier> $suppliers порядок важен: сначала основной */
    public function __construct(private array $suppliers) {}

    /** @return list<Supplier> в порядке предпочтения */
    public function ordered(): array
    {
        return array_values($this->suppliers);
    }

    public function get(string $id): Supplier
    {
        return $this->suppliers[$id] ?? throw new InvalidArgumentException("unknown supplier: {$id}");
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->suppliers);
    }
}
