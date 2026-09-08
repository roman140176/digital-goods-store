<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Realtime\EventBus;
use App\Domain\Realtime\EventCursor;
use App\Domain\Realtime\SseClient;
use App\Domain\Realtime\StreamHub;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PgSql\Connection;

/**
 * SSE-стример живой витрины: один процесс, один цикл событий, один LISTEN.
 *
 * Почему отдельный процесс, а не обычный контроллер за php-fpm: SSE держит
 * соединение открытым часами, а fpm-воркер занят ровно одним запросом всё
 * его время. Пул воркеров на витрине небольшой, и пять открытых вкладок
 * проверяющего съели бы его целиком — вместе с обычным API. Встроенный
 * `php -S` от этого не спасает: даже с PHP_CLI_SERVER_WORKERS=N он держит
 * ровно N одновременных соединений, то есть проблема просто сдвигается на
 * N+1-ю вкладку. Один stream_select-цикл держит сотни соединений одним
 * процессом и одним подключением к базе.
 *
 * Почему artisan-команда, а не самостоятельный PHP-скрипт рядом с проектом:
 * конфигурация, доступ к базе и вывод те же, что у остального кода, а логика
 * курсоров живёт в обычных классах и покрывается обычными тестами
 * (EventCursorTest, StreamTailTest). Долгоживущий процесс на Laravel в
 * проекте уже есть — queue:work, — включая переподключение PDO после обрыва
 * связи с базой.
 *
 * Доставка событий держится на двух независимых механизмах, и это не
 * дублирование ради надёжности «на всякий случай»:
 *  - NOTIFY — основной путь, он даёт задержку в единицы миллисекунд и
 *    приходит строго в момент COMMIT публикующей транзакции;
 *  - догоняющий опрос раз в две секунды — необходимая страховка: NOTIFY
 *    доставляется по тому же соединению, что и всё остальное, и обрыв этого
 *    соединения теряет уведомления безвозвратно. Без опроса витрина после
 *    рестарта базы замерла бы навсегда, при этом выглядя работающей.
 */
final class StreamServer extends Command
{
    protected $signature = 'stream:serve
        {--host=0.0.0.0 : Адрес прослушивания}
        {--port=8090 : Порт прослушивания}
        {--max-connections=200 : Предел одновременных соединений}';

    protected $description = 'Держит SSE-поток живой витрины (журнал событий + LISTEN/NOTIFY)';

    /** Единственный путь, который обслуживает стример. */
    private const PATH = '/api/stream';

    /**
     * Сколько цикл спит в stream_select, если ничего не происходит.
     *
     * Это не задержка доставки: событие будит цикл через NOTIFY. От этого
     * значения зависит только точность таймеров (heartbeat, перескок дырки),
     * и секунды для них с запасом достаточно.
     */
    private const SELECT_TIMEOUT = 1;

    /** Догоняющий опрос журнала — страховка от потерянного NOTIFY. */
    private const POLL_INTERVAL = 2.0;

    /** Тишина, после которой в поток уходит комментарий-пульс. */
    private const HEARTBEAT_INTERVAL = 15.0;

    /** Пауза перед повторной попыткой подписаться на канал уведомлений. */
    private const LISTENER_RETRY_INTERVAL = 2.0;

    /** Сколько топиков разрешено одному подключению. */
    private const MAX_TOPICS = 20;

    /** @var resource|null */
    private $server = null;

    private ?Connection $listener = null;

    /** @var resource|null */
    private $listenerSocket = null;

    private float $listenerRetryAt = 0.0;

    /**
     * Подключения по идентификатору сокета: карта нужна, чтобы после
     * stream_select найти клиента по готовому к чтению сокету за O(1).
     *
     * @var array<int, SseClient>
     */
    private array $clients = [];

    private bool $stopping = false;

    private float $polledAt = 0.0;

    /**
     * Журнал отдал полную страницу — значит хвост не дочитан, и следующий
     * такт обязан начаться без сна, иначе отставшее подключение догоняло бы
     * историю по 500 событий раз в две секунды.
     */
    private bool $tailIncomplete = false;

    public function handle(StreamHub $hub, EventBus $bus): int
    {
        $host = (string) $this->option('host');
        $port = (int) $this->option('port');
        $maxConnections = max(1, (int) $this->option('max-connections'));

        $server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);

        if ($server === false) {
            $this->error(sprintf('Не удалось занять %s:%d — %s', $host, $port, $errstr));

            return self::FAILURE;
        }

        $this->server = $server;
        stream_set_blocking($server, false);

        $this->trapSignals();
        $this->openListener();

        $this->info(sprintf(
            'Стример слушает %s:%d, путь %s, предел соединений %d.',
            $host,
            $port,
            self::PATH,
            $maxConnections,
        ));

        while (! $this->stopping) {
            $this->tick($hub, $bus, $maxConnections);
        }

        $this->shutdown();

        return self::SUCCESS;
    }

    private function tick(StreamHub $hub, EventBus $bus, int $maxConnections): void
    {
        $read = [$this->server];
        $write = [];

        foreach ($this->clients as $client) {
            $read[] = $client->socket();

            // В набор записи попадают только те, у кого буфер не пуст: так
            // цикл узнаёт о разгрузке зажатого сокета от ядра, а не опросом.
            if ($client->wantsWrite()) {
                $write[] = $client->socket();
            }
        }

        if ($this->listenerSocket !== null) {
            $read[] = $this->listenerSocket;
        }

        $except = null;
        $ready = @stream_select($read, $write, $except, $this->tailIncomplete ? 0 : self::SELECT_TIMEOUT);

        // false — сигнал прервал ожидание (EINTR). Это не ошибка: на выходе
        // из такта проверяется флаг остановки.
        if ($ready === false) {
            return;
        }

        $now = microtime(true);
        $notified = false;

        foreach ($read as $stream) {
            if ($stream === $this->server) {
                $this->accept($maxConnections, $now);

                continue;
            }

            if ($this->listenerSocket !== null && $stream === $this->listenerSocket) {
                $notified = $this->consumeNotifications();

                continue;
            }

            $client = $this->clients[get_resource_id($stream)] ?? null;

            if ($client === null) {
                continue;
            }

            if ($client->isStreaming()) {
                $client->drainInput();

                continue;
            }

            $this->handshake($client, $hub, $bus);
        }

        if ($this->listener === null && $now >= $this->listenerRetryAt) {
            $this->openListener();
        }

        if ($notified || $this->tailIncomplete || $now - $this->polledAt >= self::POLL_INTERVAL) {
            $this->polledAt = $now;
            $this->tailIncomplete = $this->fanOut($hub);
        }

        foreach ($this->clients as $client) {
            if (! $client->isStreaming()) {
                continue;
            }

            $client->cursor()->advance($now);

            if ($client->silentFor($now) >= self::HEARTBEAT_INTERVAL) {
                $client->comment('ping');
            }
        }

        // Запись — в конце такта и по всем, у кого есть что отдать, а не
        // только по отмеченным stream_select: набор записи собирался ДО
        // доставки событий, и ожидание следующего такта добавило бы к
        // задержке доставки целую секунду сна.
        foreach ($this->clients as $client) {
            $client->flush();
        }

        $this->sweep();
    }

    /**
     * Принимает всё, что накопилось в очереди прослушивающего сокета.
     *
     * Цикл, а не одно соединение за такт: stream_select сообщает лишь
     * «есть готовые», и при одновременном открытии нескольких вкладок
     * остальные ждали бы следующего пробуждения.
     */
    private function accept(int $maxConnections, float $now): void
    {
        while (true) {
            $socket = @stream_socket_accept($this->server, 0, $remote);

            if ($socket === false) {
                return;
            }

            if (count($this->clients) >= $maxConnections) {
                $this->reject($socket);

                continue;
            }

            $this->clients[get_resource_id($socket)] = new SseClient($socket, (string) $remote, $now);
        }
    }

    /**
     * Короткий отказ при переполнении: соединение принимается только чтобы
     * ответить, и сразу закрывается.
     *
     * Отвечать обязательно: молча закрытый сокет клиент видит как обрыв
     * связи и по спеке 4.6 пойдёт переподключаться с задержкой, то есть
     * упрямо долбить перегруженный процесс. Явный 503 с Retry-After даёт
     * ему причину отступить.
     *
     * @param  resource  $socket
     */
    private function reject($socket): void
    {
        $body = json_encode([
            'message' => 'Поток обновлений сейчас перегружен, попробуйте позже.',
            'reason' => 'too_many_connections',
        ], JSON_UNESCAPED_UNICODE);

        @fwrite($socket, "HTTP/1.1 503 Service Unavailable\r\n"
            ."Content-Type: application/json; charset=utf-8\r\n"
            .'Content-Length: '.strlen((string) $body)."\r\n"
            ."Retry-After: 5\r\n"
            ."Connection: close\r\n\r\n".$body);

        @fclose($socket);
    }

    /**
     * Рукопожатие: разбор запроса, решение о resync и досылка хвоста.
     *
     * Хвост и подписка на живой поток происходят внутри одного такта цикла
     * и от одного и того же курсора — окна потери между «догнали» и
     * «слушаем» нет по построению (4.4 спеки). Раздельные фазы («сначала
     * дочитать, потом подписаться») теряли бы всё, что опубликовано между
     * ними.
     */
    private function handshake(SseClient $client, StreamHub $hub, EventBus $bus): void
    {
        $head = $client->readRequestHead();

        if ($head === null) {
            return;
        }

        $request = $this->parseRequest($head);

        if ($request === null) {
            $this->refuse($client, 400, 'Некорректный запрос.', 'bad_request');

            return;
        }

        if ($request['method'] !== 'GET') {
            $this->refuse($client, 405, 'Метод не поддерживается.', 'method_not_allowed');

            return;
        }

        if ($request['path'] !== self::PATH) {
            $this->refuse($client, 404, 'Ресурс не найден.', 'not_found');

            return;
        }

        $topics = $this->parseTopics($request);
        $requested = $this->parseCursor($request);

        $client->write(
            "HTTP/1.1 200 OK\r\n"
            ."Content-Type: text/event-stream; charset=utf-8\r\n"
            ."Cache-Control: no-cache, no-transform\r\n"
            ."Connection: keep-alive\r\n"
            // Прямая просьба к nginx не буферизовать ответ: без неё поток
            // складывался бы в буфер прокси и уходил клиенту порциями.
            ."X-Accel-Buffering: no\r\n"
            ."Access-Control-Allow-Origin: *\r\n\r\n",
        );

        $window = $this->journalWindow($hub, $bus);

        if ($window === null) {
            // База недоступна прямо сейчас, головы журнала мы не знаем.
            // Рукопожатие уже отдано, поэтому подключение остаётся жить: как
            // только база вернётся, догоняющий опрос доберёт хвост от
            // запрошенного курсора. Не указан — берём ноль, то есть журнал
            // целиком: события идемпотентны (несут полное состояние), так
            // что безопасная сторона ошибки здесь — лишние события, а не
            // потерянные.
            $client->startStreaming($topics, new EventCursor(max(0, $requested)));

            return;
        }

        [$minId, $maxId] = $window;

        // Курсор не пришёл — подключение живёт «отсюда и далее», от головы
        // журнала. Ноль означал бы «отдай всю историю» и проигрывал бы
        // клиенту час событий на каждое открытие потока, хотя снапшот у него
        // и без того свежий (4.5 спеки).
        $cursor = $requested < 0 ? $maxId : $requested;

        // Курсор вне окна журнала — честный resync, а не молча урезанный
        // хвост. Слева границу задаёт ретеншн (PruneStreamEvents), справа —
        // пересоздание журнала: после `make fresh` последовательность
        // стартует заново, и клиент со старым курсором 5000 не увидел бы
        // больше НИЧЕГО, потому что "id > 5000" перестало совпадать.
        if ($requested > 0 && ($requested < $minId || $requested > $maxId)) {
            $client->send($maxId, 'resync', (string) json_encode([
                'reason' => 'cursor_out_of_journal',
                'stream_cursor' => $maxId,
            ], JSON_UNESCAPED_UNICODE));

            $cursor = $maxId;
        }

        $client->startStreaming($topics, new EventCursor($cursor));

        // Хвост подключения читается его собственным запросом, а не общей
        // выборкой живого режима: у отставшего клиента граница чтения может
        // быть на час ниже остальных, и одна общая страница на 500 событий
        // тогда обслуживала бы только его, придерживая всех прочих.
        $rows = $this->readJournal(
            static fn (): array => $hub->tail($cursor, $topics),
            'хвост подключения',
        );

        if ($rows === null) {
            return;
        }

        $this->push($client, $rows);

        if (count($rows) >= StreamHub::PAGE) {
            $this->tailIncomplete = true;
        }

        if ($this->output->isVerbose()) {
            $this->line(sprintf(
                'Подключение %s: топики [%s], курсор %d, хвост %d событий.',
                $client->remote(),
                implode(', ', $topics),
                $cursor,
                count($rows),
            ));
        }
    }

    /**
     * Живой режим: одно чтение журнала на всех подписчиков.
     *
     * Нижняя граница — минимальная граница непрерывности среди подключений,
     * поэтому одна выборка покрывает потребности каждого, а не N выборок на
     * N вкладок: при двух сотнях соединений отдельный запрос на клиента
     * превращал бы каждое событие в двести запросов к базе.
     *
     * @return bool осталась ли непрочитанная часть хвоста
     */
    private function fanOut(StreamHub $hub): bool
    {
        $floor = null;
        $topics = [];

        foreach ($this->clients as $client) {
            if (! $client->isStreaming()) {
                continue;
            }

            $contiguous = $client->cursor()->contiguous();
            $floor = $floor === null ? $contiguous : min($floor, $contiguous);

            foreach ($client->topics() as $topic) {
                $topics[$topic] = true;
            }
        }

        if ($floor === null) {
            return false;
        }

        $rows = $this->readJournal(
            static fn (): array => $hub->tail($floor, array_keys($topics)),
            'живой хвост',
        );

        if ($rows === null) {
            return false;
        }

        foreach ($this->clients as $client) {
            if ($client->isStreaming()) {
                $this->push($client, $rows);
            }
        }

        return count($rows) >= StreamHub::PAGE;
    }

    /**
     * Отдаёт подключению те события выборки, которые ему причитаются.
     *
     * Курсор отмечает КАЖДУЮ строку выборки, включая отфильтрованные по
     * чужому топику: для этого подключения такая строка обработана
     * окончательно, и если её не отметить, граница непрерывности застревала
     * бы на ней до тайм-аута перескока — то есть чужие события заставляли бы
     * клиента вечно перечитывать один и тот же участок журнала.
     *
     * @param  list<array{id: int, topic: string, type: string, payload: string}>  $rows
     */
    private function push(SseClient $client, array $rows): void
    {
        $cursor = $client->cursor();

        foreach ($rows as $row) {
            if ($cursor->wasDelivered($row['id'])) {
                continue;
            }

            if ($client->isSubscribedTo($row['topic'])) {
                $client->send($row['id'], $row['type'], $row['payload']);
            }

            $cursor->observe($row['id']);
        }
    }

    /**
     * Границы сохранившегося журнала: [минимальный id, максимальный id].
     *
     * @return array{0: int, 1: int}|null null — база недоступна
     */
    private function journalWindow(StreamHub $hub, EventBus $bus): ?array
    {
        return $this->readJournal(
            static fn (): array => [$hub->minEventId(), $bus->cursor()],
            'границы журнала',
        );
    }

    /**
     * Обёртка над чтением журнала: обрыв связи с базой не должен убивать
     * процесс, который держит открытые соединения.
     *
     * PDO после обрыва не восстанавливается сам — нужен явный
     * DB::reconnect() (так же делает queue:work). Такт при этом
     * пропускается: следующий догоняющий опрос через две секунды дочитает
     * всё, что было пропущено, потому что граница чтения не двигалась.
     *
     * @template TValue
     *
     * @param  callable(): TValue  $query
     * @return TValue|null
     */
    private function readJournal(callable $query, string $what): mixed
    {
        try {
            return $query();
        } catch (\Throwable $exception) {
            $this->error(sprintf('Журнал недоступен (%s): %s', $what, $exception->getMessage()));

            try {
                DB::reconnect();
            } catch (\Throwable) {
                // Соединение поднимется на одном из следующих тактов.
            }

            return null;
        }
    }

    /**
     * Разбор запроса: только строка запроса и заголовки, тело не читается.
     *
     * Свой разбор на несколько десятков строк вместо HTTP-сервера общего
     * назначения: стример обслуживает ровно один GET-путь без тела,
     * загрузок и маршрутизации.
     *
     * @return array{method: string, path: string, query: array<string, mixed>, headers: array<string, string>}|null
     */
    private function parseRequest(string $head): ?array
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

        return [
            'method' => strtoupper($method),
            'path' => rawurldecode($path),
            'query' => $query,
            'headers' => $headers,
        ];
    }

    /**
     * Топики подписки из ?topics=catalog,order:ord_x
     *
     * @param  array{query: array<string, mixed>, headers: array<string, string>}  $request
     * @return list<string>
     */
    private function parseTopics(array $request): array
    {
        $raw = $request['query']['topics'] ?? '';
        $topics = [];

        foreach (explode(',', is_string($raw) ? $raw : '') as $topic) {
            $topic = trim($topic);

            // Топик уходит в SQL параметром, так что дело не в инъекции:
            // список ограничивается по длине и алфавиту, чтобы подключение
            // не могло заставить процесс держать килобайты мусора и раздувать
            // IN-список общей выборки живого режима.
            if ($topic === '' || preg_match('/^[a-z]+(:[A-Za-z0-9_-]{1,64})?$/', $topic) !== 1) {
                continue;
            }

            $topics[$topic] = true;

            if (count($topics) >= self::MAX_TOPICS) {
                break;
            }
        }

        // Пустая или мусорная подписка трактуется как «витрина»: главный
        // потребитель канала — каталог, и молчащий поток вместо него сбивал
        // бы с толку при отладке куда сильнее.
        return $topics === [] ? ['catalog'] : array_keys($topics);
    }

    /**
     * Курсор подключения. Заголовок важнее параметра: при реконнекте
     * браузер присылает Last-Event-ID сам, и это значение свежее того, что
     * когда-то попало в адрес страницы.
     *
     * @param  array{query: array<string, mixed>, headers: array<string, string>}  $request
     */
    private function parseCursor(array $request): int
    {
        $value = $request['headers']['last-event-id'] ?? $request['query']['last_event_id'] ?? null;

        if (! is_string($value) && ! is_int($value)) {
            return -1;
        }

        $value = trim((string) $value);

        // Отрицательное значение — внутренний признак «курсор не указан»:
        // ноль здесь занят и значит «отдай журнал с самого начала».
        if ($value === '' || preg_match('/^\d+$/', $value) !== 1) {
            return -1;
        }

        return (int) $value;
    }

    private function refuse(SseClient $client, int $status, string $message, string $reason): void
    {
        $body = json_encode(['message' => $message, 'reason' => $reason], JSON_UNESCAPED_UNICODE);

        $client->write(sprintf("HTTP/1.1 %d %s\r\n", $status, match ($status) {
            400 => 'Bad Request',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            default => 'Error',
        })
            ."Content-Type: application/json; charset=utf-8\r\n"
            .'Content-Length: '.strlen((string) $body)."\r\n"
            ."Connection: close\r\n\r\n".$body);

        // Ответ короткий и заведомо влезает в буфер сокета целиком, поэтому
        // одной попытки записи достаточно и ждать готовности не нужно.
        $client->flush();
        $client->close();
    }

    /**
     * Подписка на канал уведомлений.
     *
     * ext-pgsql, а не PDO: LISTEN/NOTIFY требует чтения асинхронных
     * сообщений с сокета соединения, а PDO такого интерфейса не даёт вовсе —
     * подписаться через него физически нечем.
     */
    private function openListener(): void
    {
        $this->listenerRetryAt = microtime(true) + self::LISTENER_RETRY_INTERVAL;

        // PGSQL_CONNECT_FORCE_NEW обязателен: без него pg_connect отдаёт
        // соединение из своего кэша по той же строке подключения, то есть
        // после обрыва вернул бы тот же самый сломанный дескриптор, и
        // переподключение никогда бы не состоялось.
        $listener = @pg_connect($this->listenerDsn(), PGSQL_CONNECT_FORCE_NEW);

        if ($listener === false) {
            $this->error('Канал уведомлений недоступен, работаю на догоняющем опросе.');

            return;
        }

        if (@pg_query($listener, 'LISTEN '.EventBus::CHANNEL) === false) {
            $this->error('Не удалось подписаться на канал уведомлений.');
            @pg_close($listener);

            return;
        }

        $socket = pg_socket($listener);

        if ($socket === false) {
            $this->error('У соединения канала уведомлений нет сокета.');
            @pg_close($listener);

            return;
        }

        $this->listener = $listener;

        // Сокет берётся один раз и переиспользуется: каждый вызов
        // pg_socket() заводит новый stream-ресурс на тот же дескриптор, и
        // вызов на каждом такте копил бы их сотнями в минуту.
        $this->listenerSocket = $socket;

        $this->info('Подписка на канал '.EventBus::CHANNEL.' установлена.');
    }

    /**
     * Строка подключения для ext-pgsql из той же конфигурации, что и PDO:
     * два разных источника настроек базы в одном процессе — гарантированное
     * расхождение при первом же переносе стенда.
     */
    private function listenerDsn(): string
    {
        $config = (array) config('database.connections.pgsql');

        $pairs = [
            'host' => (string) ($config['host'] ?? '127.0.0.1'),
            'port' => (string) ($config['port'] ?? 5432),
            'dbname' => (string) ($config['database'] ?? ''),
            'user' => (string) ($config['username'] ?? ''),
            'password' => (string) ($config['password'] ?? ''),
            // Подключение блокирующее, а процесс в это время не обслуживает
            // никого: без предела ожидания недоступная база подвесила бы
            // весь стример вместо работы на догоняющем опросе.
            'connect_timeout' => '3',
            // Видно в pg_stat_activity — при разборе «кто держит соединение»
            // это первое, на что смотрят.
            'application_name' => 'stream:serve',
        ];

        return implode(' ', array_map(
            static fn (string $key, string $value): string => $key."='".addcslashes($value, "'\\")."'",
            array_keys($pairs),
            $pairs,
        ));
    }

    /**
     * Вычитывает накопившиеся уведомления.
     *
     * Полезная нагрузка уведомления (id события) сознательно не
     * используется: она отвечает лишь на вопрос «в журнале что-то
     * появилось». Читать журнал ОТ ЭТОГО id было бы той самой ошибкой, от
     * которой защищает EventCursor, — событие с меньшим номером могло
     * закоммититься позже и было бы пропущено навсегда. Поэтому чтение
     * всегда идёт от границы непрерывности, а уведомление служит только
     * будильником.
     *
     * @return bool появился ли повод перечитать журнал
     */
    private function consumeNotifications(): bool
    {
        if ($this->listener === null) {
            return false;
        }

        if (@pg_consume_input($this->listener) === false
            || pg_connection_status($this->listener) !== PGSQL_CONNECTION_OK) {
            $this->closeListener();
            $this->error('Соединение канала уведомлений потеряно, переподключаюсь.');

            // Уведомления, потерянные вместе с соединением, дочитает опрос —
            // поэтому такт всё равно заканчивается чтением журнала.
            return true;
        }

        $seen = false;

        while (@pg_get_notify($this->listener) !== false) {
            $seen = true;
        }

        return $seen;
    }

    private function closeListener(): void
    {
        if ($this->listener !== null) {
            @pg_close($this->listener);
        }

        // Сокет закрывать отдельно нельзя: он принадлежит соединению,
        // и pg_close уже освободил дескриптор.
        $this->listener = null;
        $this->listenerSocket = null;
        $this->listenerRetryAt = microtime(true) + self::LISTENER_RETRY_INTERVAL;
    }

    private function sweep(): void
    {
        foreach ($this->clients as $id => $client) {
            if (! $client->isDisconnected()) {
                continue;
            }

            $client->close();
            unset($this->clients[$id]);

            if ($this->output->isVerbose()) {
                $this->line('Подключение '.$client->remote().' закрыто.');
            }
        }
    }

    /**
     * Аккуратная остановка по сигналу от `docker compose stop`.
     *
     * SIGQUIT в списке стоит не для полноты: базовый образ php:fpm
     * объявляет STOPSIGNAL SIGQUIT (php-fpm по нему завершается корректно),
     * поэтому docker посылает именно его, а не SIGTERM. Без обработки
     * SIGQUIT команда не реагировала бы на остановку вообще и получала бы
     * SIGKILL через десять секунд ожидания — проверено замером: остановка
     * контейнера занимала 10,5 с и обрывала процесс на полуслове. Ровно по
     * этой же причине SIGQUIT первым ловит и queue:work.
     *
     * Смысл аккуратности не в самих сокетах — их закрыло бы и ядро, — а в
     * том, что клиенты получают FIN сразу и переподключаются к новому
     * процессу, не дожидаясь тайм-аута.
     */
    private function trapSignals(): void
    {
        if (! function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGQUIT, SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->stopping = true;
            });
        }
    }

    private function shutdown(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }

        $this->clients = [];

        if ($this->listener !== null) {
            @pg_close($this->listener);
            $this->listener = null;
            $this->listenerSocket = null;
        }

        if (is_resource($this->server)) {
            fclose($this->server);
        }

        $this->info('Стример остановлен.');
    }
}
