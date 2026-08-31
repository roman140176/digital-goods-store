<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Delivery\IssueOrderCode;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Выдача идёт в очереди, а не в обработчике вебхука: контракт требует
 * быстрый 200, а поставщик по условиям задачи умеет подвисать.
 *
 * Уникальность задачи в очереди намеренно НЕ используется: гарантия
 * однократности живёт в базе (одна строка выдачи на заказ, детерминированный
 * request_id), а не в инфраструктуре очереди. Даже если задача продублируется
 * или воркеров будет несколько, второй ключ не уйдёт.
 */
final class DeliverOrder implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public function __construct(public readonly int $deliveryId)
    {
        // Задача не выпускается до коммита транзакции: иначе воркер успел бы
        // заглянуть в базу раньше, чем там появился заказ.
        $this->afterCommit();
    }

    public function handle(IssueOrderCode $issue): void
    {
        $issue($this->deliveryId);
    }
}
