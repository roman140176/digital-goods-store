<?php

declare(strict_types=1);

namespace App\Domain\Realtime;

use Illuminate\Support\Facades\DB;

/**
 * Чтение журнала реалтайм-событий со стороны стримера.
 *
 * Отдельный класс от EventBus, потому что это ровно противоположная сторона
 * канала: EventBus пишет событие в транзакции бизнес-операции и живёт внутри
 * php-fpm, StreamHub только читает и живёт в долгоживущем процессе стримера.
 * Общего состояния у них нет — связь идёт через таблицу.
 *
 * Чтение всегда «id > граница ORDER BY id»: это единственная форма запроса,
 * которая переживает неупорядоченность коммитов (см. EventCursor).
 */
final class StreamHub
{
    /**
     * Сколько событий отдавать за один запрос.
     *
     * Ограничение обязательно: подключение с очень старым Last-Event-ID
     * иначе вытянуло бы весь час журнала одним массивом в память процесса,
     * который обслуживает ещё две сотни соединений. Недобранный хвост
     * досылается следующими запросами — вызывающий код видит полную
     * страницу и продолжает чтение, не дожидаясь очередного опроса.
     */
    public const PAGE = 500;

    /**
     * Хвост журнала после указанной границы по интересующим топикам.
     *
     * payload отдаётся текстом (`payload::text`), а не разобранным массивом,
     * специально: в SSE он уйдёт как есть, и промежуточный
     * json_decode → json_encode был бы двойной работой на каждое событие
     * для каждого подключения, да ещё и с риском изменить представление
     * чисел. Клиент разберёт JSON сам.
     *
     * @param  list<string>  $topics
     * @return list<array{id: int, topic: string, type: string, payload: string}>
     */
    public function tail(int $afterId, array $topics, int $limit = self::PAGE): array
    {
        // Пустой список топиков — не «все топики», а «никакие»: подписки нет,
        // и `topic IN ()` было бы вдобавок синтаксической ошибкой.
        if ($topics === []) {
            return [];
        }

        // LIMIT подставляется числом, а не параметром: значение приходит из
        // конфигурации процесса, приведение к int закрывает инъекцию, а
        // параметр в LIMIT заставил бы PostgreSQL выводить его тип.
        $limit = max(1, min($limit, 2000));
        $placeholders = implode(',', array_fill(0, count($topics), '?'));

        $rows = DB::select(
            "SELECT id, topic, type, payload::text AS payload
               FROM stream_events
              WHERE id > ? AND topic IN ({$placeholders})
              ORDER BY id
              LIMIT {$limit}",
            [$afterId, ...array_values($topics)],
        );

        return array_map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'topic' => $row->topic,
            'type' => $row->type,
            'payload' => $row->payload,
        ], $rows);
    }

    /**
     * Самый ранний сохранившийся id журнала.
     *
     * Нужен для решения о `resync` (4.4 спеки): если клиент присылает курсор
     * старше этой границы, часть истории уже удалена ретеншном, и честный
     * ответ — «пересобери снапшот целиком», а не молча отданный неполный
     * хвост. Ноль на пустом журнале, чтобы вызывающий код не разбирал null
     * отдельной ветвью.
     */
    public function minEventId(): int
    {
        return (int) (DB::scalar('SELECT coalesce(min(id), 0) FROM stream_events') ?? 0);
    }
}
