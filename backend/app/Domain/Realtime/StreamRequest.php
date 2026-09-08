<?php

declare(strict_types=1);

namespace App\Domain\Realtime;

/**
 * Разобранный запрос на подписку: метод, путь, топики и курсор.
 *
 * Выделено из StreamServer отдельным классом после ревью, и не ради
 * уменьшения команды: это ЧИСТАЯ функция от строки заголовков, и именно она
 * держит контракт, на который опирается фронт, — приоритет заголовка
 * `Last-Event-ID` над параметром `?last_event_id=`, разбор списка топиков и
 * его предел. Внутри команды тот же разбор жил приватными методами и
 * проверялся только вручную через curl: любая правда о нём доставалась
 * поднятым контейнером, сокетом и живой базой. Здесь она достаётся вызовом
 * функции. Открывать приватные методы «для теста» было альтернативой хуже:
 * тест лип бы к внутренностям класса, а не к контракту.
 *
 * Разбор свой, а не HTTP-сервером общего назначения: стример обслуживает
 * ровно один GET-путь без тела, загрузок и маршрутизации.
 */
final readonly class StreamRequest
{
    /**
     * Сколько топиков разрешено одному подключению.
     *
     * Топик уходит в SQL параметром, так что дело не в инъекции: предел
     * нужен, чтобы подключение не могло заставить процесс держать килобайты
     * мусора и раздувать IN-список общей выборки живого режима.
     */
    public const MAX_TOPICS = 20;

    /**
     * Подписка по умолчанию. Пустая или мусорная трактуется как «витрина»:
     * главный потребитель канала — каталог, и молчащий поток вместо него
     * сбивал бы с толку при отладке куда сильнее.
     */
    public const DEFAULT_TOPIC = 'catalog';

    /**
     * @param  list<string>  $topics
     * @param  int  $cursor  номер последнего известного клиенту события;
     *                       -1 — курсор не указан вовсе (ноль занят и значит
     *                       «отдай журнал с самого начала»)
     */
    private function __construct(
        public string $method,
        public string $path,
        public array $topics,
        public int $cursor,
    ) {}

    /**
     * Разбирает «голову» запроса — строку запроса и заголовки до пустой
     * строки. Тело не читается вовсе: у GET его нет, а SSE-клиент после
     * запроса больше ничего не присылает.
     *
     * null — запрос неразбираем (нет строки запроса или в ней меньше двух
     * частей); отвечать на такое нужно 400, а не догадками.
     */
    public static function parse(string $head): ?self
    {
        $lines = preg_split("/\r\n|\n/", $head) ?: [];
        $requestLine = array_shift($lines);

        if (! is_string($requestLine) || trim($requestLine) === '') {
            return null;
        }

        $parts = preg_split('/\s+/', trim($requestLine)) ?: [];

        if (count($parts) < 2) {
            return null;
        }

        [$method, $target] = $parts;

        $path = $target;
        $query = [];
        $mark = strpos($target, '?');

        if ($mark !== false) {
            $path = substr($target, 0, $mark);
            parse_str(substr($target, $mark + 1), $query);
        }

        $headers = [];

        foreach ($lines as $line) {
            $colon = strpos($line, ':');

            if ($colon === false) {
                continue;
            }

            // Имена заголовков регистронезависимы, а браузеры и прокси
            // пишут Last-Event-ID кто как: приводим к нижнему регистру,
            // чтобы курсор не терялся из-за регистра буквы.
            $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
        }

        return new self(
            method: strtoupper($method),
            path: rawurldecode($path),
            topics: self::topicsFrom($query),
            cursor: self::cursorFrom($query, $headers),
        );
    }

    /**
     * Топики подписки из ?topics=catalog,order:ord_x
     *
     * @param  array<string, mixed>  $query
     * @return list<string>
     */
    private static function topicsFrom(array $query): array
    {
        $raw = $query['topics'] ?? '';
        $topics = [];

        foreach (explode(',', is_string($raw) ? $raw : '') as $topic) {
            $topic = trim($topic);

            if ($topic === '' || preg_match('/^[a-z]+(:[A-Za-z0-9_-]{1,64})?$/', $topic) !== 1) {
                continue;
            }

            $topics[$topic] = true;

            if (count($topics) >= self::MAX_TOPICS) {
                break;
            }
        }

        return $topics === [] ? [self::DEFAULT_TOPIC] : array_keys($topics);
    }

    /**
     * Курсор подключения. Заголовок важнее параметра: при реконнекте
     * браузер присылает Last-Event-ID сам, и это значение свежее того, что
     * когда-то попало в адрес страницы.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     */
    private static function cursorFrom(array $query, array $headers): int
    {
        $value = $headers['last-event-id'] ?? $query['last_event_id'] ?? null;

        if (! is_string($value) && ! is_int($value)) {
            return -1;
        }

        $value = trim((string) $value);

        if ($value === '' || preg_match('/^\d+$/', $value) !== 1) {
            return -1;
        }

        return (int) $value;
    }
}
