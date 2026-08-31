<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Delivery\DeliveryState;
use App\Domain\Delivery\IssueOrderCode;
use App\Domain\Payments\ApplyPaymentEvent;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Дожимает незавершённые выдачи.
 *
 * Разбирает два случая:
 *  - воркер умер посреди выдачи, аренда задачи истекла;
 *  - поставщик отказал или склад был пуст, но с тех пор прошло время
 *    (склад могли пополнить, поставщик мог подняться).
 *
 * Повтор безопасен: request_id тот же, поэтому если поставщик успел выдать
 * код до потери ответа, вернётся тот же код — ключ не теряется и не задваивается.
 */
final class ReconcileDeliveries extends Command
{
    protected $signature = 'deliveries:reconcile {--limit=100}';

    protected $description = 'Повторяет выдачи, зависшие из-за таймаутов, отказов и пустого склада';

    public function handle(IssueOrderCode $issue, ApplyPaymentEvent $events): int
    {
        $idleAfter = (int) config('store.reconcile_after_seconds');
        $idleSince = now()->subSeconds($idleAfter);

        // Сначала события: обработка могла прерваться на полпути, и тогда
        // заказ до сих пор не знает, что он оплачен.
        $recoveredEvents = $events->applyUnprocessed($idleAfter);

        $ids = DB::table('deliveries')
            ->where(function (Builder $query) use ($idleSince): void {
                $query->whereIn('state', [
                    DeliveryState::Pending->value,
                    DeliveryState::Failed->value,
                    DeliveryState::OutOfStock->value,
                ])->where('updated_at', '<=', $idleSince);
            })
            ->orWhere(function (Builder $query): void {
                $query->where('state', DeliveryState::InProgress->value)
                    ->whereNotNull('locked_until')
                    ->where('locked_until', '<=', now());
            })
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->pluck('id');

        foreach ($ids as $id) {
            $issue((int) $id);
        }

        $this->info(sprintf(
            'Досланных событий: %d, обработанных выдач: %d',
            $recoveredEvents,
            $ids->count(),
        ));

        return self::SUCCESS;
    }
}
