<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Чистит журнал реалтайм-событий.
 *
 * Ретеншн в час — не про объём данных, а про контракт SSE-протокола
 * (см. 4.4 спеки): если клиент присылает Last-Event-ID старше самого
 * раннего сохранённого id, стример обязан честно ответить resync и заставить
 * клиента пересобрать снапшот заново, а не молча пропустить часть истории.
 * Час — запас на обрыв связи и "ноутбук закрыли на выходные" сверху не
 * даётся: это осознанная граница между "reconnect с хвостом" и "resync".
 */
final class PruneStreamEvents extends Command
{
    protected $signature = 'stream:prune';

    protected $description = 'Удаляет события реалтайм-журнала старше часа';

    public function handle(): int
    {
        $deleted = DB::affectingStatement(
            "DELETE FROM stream_events WHERE created_at < now() - interval '1 hour'",
        );

        $this->info(sprintf('Удалено устаревших событий журнала: %d', $deleted));

        return self::SUCCESS;
    }
}
