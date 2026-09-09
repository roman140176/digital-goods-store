<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Delivery\Supplier;
use App\Domain\Delivery\SupplierOutcome;

/**
 * Поставщик со заранее заданным сценарием ответов.
 * Позволяет проверять развилки выдачи без сети и без заглушек по HTTP.
 */
final class FakeSupplier implements Supplier
{
    /** @var list<string> request_id каждого обращения */
    public array $calls = [];

    /**
     * @param  list<SupplierOutcome>  $script
     * @param  int|null  $restockCap  максимум, который restock() готов
     *                                добавить за один вызов — null (по
     *                                умолчанию) добавляет ровно запрошенное,
     *                                как настоящий склад с запасом. Заданное
     *                                число имитирует усечение на стороне
     *                                поставщика (см. supplier/public/index.php,
     *                                /restock и его min(50000, ...)).
     */
    public function __construct(
        private readonly string $id,
        private array $script,
        private readonly ?int $restockCap = null,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function issue(string $requestId, string $sku, string $orderId): SupplierOutcome
    {
        $this->calls[] = $requestId;

        return array_shift($this->script) ?? SupplierOutcome::ambiguous('script_exhausted');
    }

    public function inventory(): array
    {
        return ['supplier' => $this->id, 'total' => 0, 'free' => 0, 'issued' => 0];
    }

    public function restock(int $count): array
    {
        $added = $this->restockCap === null ? $count : min($count, $this->restockCap);

        return ['supplier' => $this->id, 'added' => $added];
    }
}
