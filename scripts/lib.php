<?php

declare(strict_types=1);

/**
 * Инструментарий состязательных прогонов.
 *
 * Параллельность настоящая: все запросы залпа добавляются в один
 * curl_multi-хэндл и уходят одновременно, а не по очереди.
 *
 * Скрипты запускаются внутри контейнера app, поэтому по умолчанию адреса
 * внутренние (nginx, supplier-a, supplier-b). Переопределяются через
 * RACE_BASE_URL, RACE_SUPPLIER_A_URL, RACE_SUPPLIER_B_URL, RACE_ADMIN_TOKEN.
 *
 * Задача 14 добавляет три новых средства поверх HTTP:
 *  - прямое чтение базы (RACE_DB_*) — не всякий инвариант виден в ответе API
 *    (например, «ровно одна строка stock_units в состоянии reserved»), и
 *    задача прямо требует опираться на состояние в базе, а не только на
 *    ответы эндпоинтов. Пишем в базу напрямую только в одном месте
 *    (race-stream-catchup.php, имитация ретеншна журнала) — везде, где
 *    правку можно сделать существующей HTTP-ручкой (dev/admin), используется
 *    именно она;
 *  - запуск artisan-команды из скрипта (`artisan_start`/`artisan_finish`) —
 *    нужен ровно там, где сценарий обязан гарантированно, а не по случайному
 *    совпадению фаз секундного тика планировщика, столкнуть в одном и том же
 *    интервале времени две транзакции — оплату и reservations:release.
 *    Скрипт выполняется внутри контейнера app, где лежит тот же artisan,
 *    что использует сам планировщик;
 *  - SSE-клиент на fsockopen (`SseTestClient`) — для race-stream-catchup.php,
 *    честный сокет вместо curl: curl не даёт читать событие за событием из
 *    незакрытого потока.
 */

final class Race
{
    /** @var list<array{ok: bool, text: string, detail: string}> */
    private static array $checks = [];

    private static ?string $title = null;

    public static function title(string $text): void
    {
        self::$title = $text;
        fwrite(STDOUT, "\n\033[1m".$text."\033[0m\n".str_repeat('-', max(20, mb_strlen($text)))."\n");
    }

    public static function step(string $text): void
    {
        fwrite(STDOUT, '  · '.$text."\n");
    }

    public static function check(bool $ok, string $text, string $detail = ''): bool
    {
        self::$checks[] = ['ok' => $ok, 'text' => $text, 'detail' => $detail];

        fwrite(STDOUT, sprintf(
            "  %s %s%s\n",
            $ok ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m",
            $text,
            $detail === '' ? '' : "\n         ".$detail,
        ));

        return $ok;
    }

    /** @return int код выхода: 0 если все проверки прошли */
    public static function summary(): int
    {
        $failed = array_filter(self::$checks, static fn (array $c): bool => ! $c['ok']);

        fwrite(STDOUT, sprintf(
            "\n  Итог: %d из %d проверок пройдено. %s\n",
            count(self::$checks) - count($failed),
            count(self::$checks),
            $failed === [] ? "\033[32mСЦЕНАРИЙ ПРОЙДЕН\033[0m" : "\033[31mСЦЕНАРИЙ ПРОВАЛЕН\033[0m",
        ));

        return $failed === [] ? 0 : 1;
    }
}

function env_str(string $key, string $default): string
{
    $value = getenv($key);

    return $value === false || $value === '' ? $default : $value;
}

function base_url(): string
{
    return rtrim(env_str('RACE_BASE_URL', 'http://nginx'), '/');
}

function supplier_url(string $id): string
{
    return rtrim(env_str('RACE_SUPPLIER_'.strtoupper($id).'_URL', 'http://supplier-'.$id), '/');
}

function admin_token(): string
{
    return env_str('RACE_ADMIN_TOKEN', 'admin-secret-token');
}

/**
 * @param array<string, mixed>|null $json
 * @param list<string> $headers
 * @return array{status: int, body: mixed, error: string|null}
 */
function request(string $method, string $url, ?array $json = null, array $headers = [], float $timeout = 30): array
{
    $handle = curl_init();
    curl_setopt_array($handle, curl_options($method, $url, $json, $headers, $timeout));

    $raw = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_errno($handle) !== 0 ? curl_error($handle) : null;
    curl_close($handle);

    return [
        'status' => $status,
        'body' => is_string($raw) ? json_decode($raw, true) : null,
        'error' => $error,
    ];
}

/**
 * Одновременный залп. Все запросы стартуют вместе — именно это и создаёт гонку.
 *
 * @param list<array{method: string, url: string, json?: array<string, mixed>, headers?: list<string>}> $requests
 * @return list<array{status: int, body: mixed, error: string|null}>
 */
function volley(array $requests, float $timeout = 60): array
{
    $multi = curl_multi_init();
    $handles = [];

    foreach ($requests as $index => $spec) {
        $handle = curl_init();
        curl_setopt_array($handle, curl_options(
            $spec['method'],
            $spec['url'],
            $spec['json'] ?? null,
            $spec['headers'] ?? [],
            $timeout,
        ));
        curl_multi_add_handle($multi, $handle);
        $handles[$index] = $handle;
    }

    do {
        $status = curl_multi_exec($multi, $running);
        if ($running) {
            curl_multi_select($multi, 0.1);
        }
    } while ($running && $status === CURLM_OK);

    $results = [];
    foreach ($handles as $index => $handle) {
        $raw = curl_multi_getcontent($handle);
        $results[$index] = [
            'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            'body' => is_string($raw) ? json_decode($raw, true) : null,
            'error' => curl_errno($handle) !== 0 ? curl_error($handle) : null,
        ];
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }

    curl_multi_close($multi);
    ksort($results);

    return array_values($results);
}

/**
 * @param array<string, mixed>|null $json
 * @param list<string> $headers
 * @return array<int, mixed>
 */
function curl_options(string $method, string $url, ?array $json, array $headers, float $timeout): array
{
    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
        CURLOPT_CONNECTTIMEOUT_MS => 5000,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
    ];

    if ($json !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE);
        $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
    }

    return $options;
}

/** @return array<string, mixed> */
function create_order(string $sku, ?string $promo = null, ?string $idempotencyKey = null): array
{
    $payload = ['sku' => $sku];
    if ($promo !== null) {
        $payload['promo_code'] = $promo;
    }

    $response = request('POST', base_url().'/api/orders', $payload, [
        'Idempotency-Key: '.($idempotencyKey ?? bin2hex(random_bytes(12))),
    ]);

    if (! is_array($response['body'])) {
        throw new RuntimeException('не удалось создать заказ: HTTP '.$response['status'].' '.(string) $response['error']);
    }

    return $response['body'];
}

/** @return array<string, mixed> */
function get_order(string $id): array
{
    $response = request('GET', base_url().'/api/orders/'.$id);

    return is_array($response['body']) ? $response['body'] : [];
}

/** @return array{method: string, url: string, json: array<string, mixed>} */
function webhook_request(string $orderId, int $totalMinor, string $eventId, string $status = 'paid', ?string $createdAt = null): array
{
    return [
        'method' => 'POST',
        'url' => base_url().'/api/webhook/payment',
        'json' => [
            'event_id' => $eventId,
            'order_id' => $orderId,
            'status' => $status,
            'amount' => $totalMinor / 100,
            'currency' => 'RUB',
            'created_at' => $createdAt ?? gmdate('c'),
        ],
    ];
}

/** Ждёт выполнения условия: выдача асинхронная. */
function wait_for(callable $predicate, float $seconds = 30, float $interval = 0.3): bool
{
    $deadline = microtime(true) + $seconds;

    do {
        if ($predicate()) {
            return true;
        }
        usleep((int) ($interval * 1_000_000));
    } while (microtime(true) < $deadline);

    return false;
}

function wait_for_final(string $orderId, float $seconds = 40): array
{
    $order = [];

    wait_for(function () use ($orderId, &$order): bool {
        $order = get_order($orderId);

        return in_array($order['status'] ?? '', ['delivered', 'payment_failed', 'out_of_stock', 'delivery_failed'], true);
    }, $seconds);

    return $order;
}

/** @return array<string, mixed> */
function supplier_inventory(string $id): array
{
    $response = request('GET', supplier_url($id).'/inventory');

    return is_array($response['body']) ? $response['body'] : [];
}

/** @param array<string, mixed> $values */
function supplier_config(string $id, array $values): array
{
    $response = request('POST', supplier_url($id).'/config', $values);

    return is_array($response['body']) ? $response['body'] : [];
}

function supplier_drain(string $id): array
{
    $response = request('POST', supplier_url($id).'/drain', []);

    return is_array($response['body']) ? $response['body'] : [];
}

function supplier_restock(string $id, int $count): array
{
    $response = request('POST', supplier_url($id).'/restock', ['count' => $count]);

    return is_array($response['body']) ? $response['body'] : [];
}

/** Сколько ключей израсходовано суммарно у обоих поставщиков. */
function total_issued(): int
{
    return (int) (supplier_inventory('a')['issued'] ?? 0) + (int) (supplier_inventory('b')['issued'] ?? 0);
}

function total_free(): int
{
    return (int) (supplier_inventory('a')['free'] ?? 0) + (int) (supplier_inventory('b')['free'] ?? 0);
}

/** Возвращает поставщиков в спокойное состояние: без отказов и таймаутов. */
function suppliers_calm(): void
{
    foreach (['a', 'b'] as $id) {
        supplier_config($id, [
            'error_rate' => 0,
            'timeout_rate' => 0,
            'issue_then_timeout' => 0,
            'issue_then_error' => 0,
            'timeout_seconds' => 10,
        ]);
    }
}

/**
 * Приводит поставщиков в рабочее состояние перед сценарием: сбои сняты,
 * остаток достаточен. Пул из ТЗ намеренно НЕ пересевается — коды не должны
 * повторяться между сценариями, иначе один код ушёл бы в два заказа.
 */
function suppliers_ready(int $minFree = 5): void
{
    suppliers_calm();

    // Остаток нужен у КАЖДОГО поставщика: суммарный счёт врал, если весь
    // остаток лежал у одного, и сценарий про резервного поставщика падал
    // по внешней причине.
    foreach (['a', 'b'] as $id) {
        $free = (int) (supplier_inventory($id)['free'] ?? 0);

        if ($free < $minFree) {
            supplier_restock($id, ($minFree - $free) * 2);
        }
    }
}

/**
 * Какие ключи закреплены за конкретным заказом у обоих поставщиков.
 * Это и есть прямая проверка однократности: список обязан быть длиной 1.
 *
 * @return list<string>
 */
function keys_for_order(string $orderId): array
{
    $requestId = 'req_'.$orderId;
    $codes = [];

    foreach (['a', 'b'] as $id) {
        $response = request('GET', supplier_url($id).'/issued/'.rawurlencode($requestId));
        $body = is_array($response['body']) ? $response['body'] : [];

        foreach (($body['codes'] ?? []) as $code) {
            $codes[] = $id.':'.$code;
        }
    }

    return $codes;
}

/** Полный сброс складов обоих поставщиков к исходному пулу из ТЗ. */
function suppliers_reset(): void
{
    foreach (['a', 'b'] as $id) {
        request('POST', supplier_url($id).'/reset', []);
    }
}

/** @return array<string, mixed> состояние одного промокода */
function promo_state(string $code): array
{
    $response = request('GET', base_url().'/admin/promocodes?token='.urlencode(admin_token()));
    $codes = is_array($response['body']) ? ($response['body']['promocodes'] ?? []) : [];

    foreach ($codes as $promo) {
        if (($promo['code'] ?? null) === $code) {
            return $promo;
        }
    }

    return [];
}

function admin_post(string $path, array $json = []): array
{
    $response = request('POST', base_url().$path.'?token='.urlencode(admin_token()), $json);

    return ['status' => $response['status'], 'body' => $response['body']];
}

// ------------------------------------------------------------------
// Задача 14: предложения по offer_id, прямое чтение базы, artisan, SSE.
// ------------------------------------------------------------------

/** @return list<array<string, mixed>> активные предложения позиции, дешёвые впереди (форма OfferState) */
function offers_for(string $sku): array
{
    $response = request('GET', base_url().'/api/offers?sku='.urlencode($sku));
    $body = is_array($response['body']) ? $response['body'] : [];

    return $body['offers'] ?? [];
}

/** @return array<string, mixed> самое дешёвое активное предложение позиции */
function cheapest_offer(string $sku): array
{
    $offers = offers_for($sku);

    if ($offers === []) {
        throw new RuntimeException("у позиции {$sku} нет ни одного активного предложения со свободной единицей");
    }

    return $offers[0];
}

/**
 * Гарантирует, что у самого дешёвого активного предложения позиции есть не
 * меньше $minUnits свободных единиц ПРЯМО СЕЙЧАС, никогда не уменьшая
 * остаток, если он и так больше.
 *
 * Нужен из-за задачи 2 второго этапа: цена и остаток переехали с товара на
 * предложение продавца, и у самого дешёвого предложения на позицию — не
 * бесконечный склад первого этапа, а скромные единицы стока (у KEY-CS2-PRIME
 * — намеренно ровно одна, см. race-last-unit.php). Восемь сценариев первого
 * этапа покупают по голому sku и делят этот остаток между собой в общем
 * прогоне make race-all; без подкачки первый же сценарий, тронувший позицию,
 * забирал бы единственную единицу и молча уводил все последующие на другое
 * предложение — с другой ценой и другим supplier_id, ломая их же собственные
 * проверки. Подкачка возвращает сценариям допущение первого этапа «единиц
 * достаточно», не меняя ни одной их проверки.
 *
 * @return array<string, mixed> предложение (форма /api/offers) с учётом подкачки
 */
function ensure_offer_available(string $sku, int $minUnits): array
{
    $offer = cheapest_offer($sku);
    $target = max((int) $offer['available'], $minUnits);

    if ($target !== (int) $offer['available']) {
        admin_post('/admin/offers/'.$offer['offer_id'].'/stock', ['units' => $target]);
        $offer['available'] = $target;
    }

    return $offer;
}

/** Заказ по конкретному offer_id — прицельная покупка вместо резолвинга по sku. */
function create_order_for_offer(int $offerId, ?string $promo = null, ?string $idempotencyKey = null): array
{
    $payload = ['offer_id' => $offerId];
    if ($promo !== null) {
        $payload['promo_code'] = $promo;
    }

    $response = request('POST', base_url().'/api/orders', $payload, [
        'Idempotency-Key: '.($idempotencyKey ?? bin2hex(random_bytes(12))),
    ]);

    if (! is_array($response['body'])) {
        throw new RuntimeException('не удалось создать заказ по offer_id: HTTP '.$response['status'].' '.(string) $response['error']);
    }

    return $response['body'];
}

/**
 * Подключение к Postgres напрямую — только на чтение состояния, которое не
 * отдаёт ни один эндпоинт (например, состояния конкретных строк
 * stock_units). Мутации по-прежнему идут через dev/admin-ручки — прямая
 * запись в базу используется ровно в одном сценарии (race-stream-catchup.php,
 * имитация ретеншна журнала), и там это отдельно объяснено.
 *
 * Переменные окружения контейнера app те же, что использует сам Laravel
 * (backend/.env приходит в контейнер через env_file), поэтому подключение
 * получается без отдельной конфигурации. RACE_DB_* — override на случай,
 * если скрипт когда-нибудь запустят не из этого контейнера.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = env_str('RACE_DB_HOST', env_str('DB_HOST', 'db'));
    $port = env_str('RACE_DB_PORT', env_str('DB_PORT', '5432'));
    $name = env_str('RACE_DB_NAME', env_str('DB_DATABASE', 'store'));
    $user = env_str('RACE_DB_USER', env_str('DB_USERNAME', 'app'));
    $pass = env_str('RACE_DB_PASSWORD', env_str('DB_PASSWORD', 'secret'));

    $pdo = new PDO("pgsql:host={$host};port={$port};dbname={$name}", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

/** @return list<array<string, mixed>> */
function db_rows(string $sql, array $params = []): array
{
    $statement = db()->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

/** Первая колонка первой строки, null — пустой результат. */
function db_value(string $sql, array $params = []): mixed
{
    $statement = db()->prepare($sql);
    $statement->execute($params);
    $value = $statement->fetchColumn();

    return $value === false ? null : $value;
}

/** @return int число затронутых строк */
function db_exec(string $sql, array $params = []): int
{
    $statement = db()->prepare($sql);
    $statement->execute($params);

    return $statement->rowCount();
}

/**
 * Запускает artisan-команду отдельным процессом и сразу возвращает
 * управление — не дожидаясь завершения.
 *
 * Нужен там, где сценарий обязан гарантированно, а не по случайному
 * совпадению фаз секундного тика scheduler-контейнера, столкнуть в одном и
 * том же интервале времени две транзакции — оплату (обычный HTTP-запрос) и
 * reservations:release. Планировщик тикает и без этого вызова (он не
 * останавливается на время сценария), поэтому это ДОПОЛНИТЕЛЬНЫЙ, а не
 * единственный источник release — гонка от этого не становится менее
 * настоящей: сталкиваются реальные транзакции над реальными строками.
 *
 * @return array{process: resource, stdout: resource, stderr: resource}
 */
function artisan_start(string $command): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    // Командный массив, а не строка: proc_open запускает бинарь напрямую,
    // без шелла, и аргументам не нужно экранирование.
    $process = proc_open(
        [PHP_BINARY, 'artisan', ...preg_split('/\s+/', trim($command))],
        $descriptors,
        $pipes,
        '/var/www',
    );

    if (! is_resource($process)) {
        throw new RuntimeException('не удалось запустить artisan '.$command);
    }

    return ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
}

/**
 * @param array{process: resource, stdout: resource, stderr: resource} $handle
 * @return array{exit: int, stdout: string, stderr: string}
 */
function artisan_finish(array $handle): array
{
    $stdout = trim((string) stream_get_contents($handle['stdout']));
    $stderr = trim((string) stream_get_contents($handle['stderr']));
    fclose($handle['stdout']);
    fclose($handle['stderr']);

    return ['exit' => proc_close($handle['process']), 'stdout' => $stdout, 'stderr' => $stderr];
}

/** Запускает artisan-команду и сразу дожидается её завершения. */
function artisan_run(string $command): array
{
    return artisan_finish(artisan_start($command));
}

/** @return array{0: string, 1: int} [хост, порт] стримера для fsockopen */
function stream_target(): array
{
    $parts = parse_url(env_str('RACE_STREAM_URL', 'http://streamer:8090'));

    return [(string) ($parts['host'] ?? 'streamer'), (int) ($parts['port'] ?? 8090)];
}

/**
 * SSE-клиент на голом сокете для race-stream-catchup.php.
 *
 * curl здесь не подходит: у него нет способа честно прочитать N событий из
 * незакрытого потока и остановиться ровно там, где нужно сценарию, —
 * CURLOPT_WRITEFUNCTION видит байты по мере поступления, но прервать запрос
 * изнутри колбэка и продолжить с тем же телом нельзя. Голый fsockopen читает
 * построчно и останавливается там, где скажет вызывающий код — это и даёт
 * «прочитать пару событий, оборвать соединение» дословно.
 */
final class SseTestClient
{
    /** @var resource */
    private $socket;

    /** @param list<string> $topics */
    public function __construct(array $topics, ?int $lastEventId = null, float $timeout = 5.0)
    {
        [$host, $port] = stream_target();

        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);

        if ($socket === false) {
            throw new RuntimeException("не удалось подключиться к потоку {$host}:{$port} — {$errstr}");
        }

        $this->socket = $socket;

        $query = 'topics='.rawurlencode(implode(',', $topics));
        if ($lastEventId !== null) {
            $query .= '&last_event_id='.$lastEventId;
        }

        fwrite($this->socket, "GET /api/stream?{$query} HTTP/1.1\r\n"
            ."Host: streamer\r\n"
            ."Accept: text/event-stream\r\n"
            ."Connection: close\r\n\r\n");

        $status = $this->readLine($timeout);

        if ($status === null || ! str_contains($status, ' 200 ')) {
            throw new RuntimeException('поток ответил не 200 OK: '.($status ?? '(соединение оборвалось)'));
        }

        // Дочитываем заголовки ответа до пустой строки-разделителя — кадры
        // событий начинаются сразу за ней.
        do {
            $line = $this->readLine($timeout);
        } while ($line !== null && $line !== '');
    }

    /**
     * Один кадр SSE (id/event/data) целиком, либо null — сокет закрылся или
     * истёк тайм-аут прежде, чем дошла пустая строка, завершающая кадр.
     *
     * Комментарии-пульсы (`: ping`, см. SseClient::comment) — не кадр:
     * строка с двоеточием и следующая за ней пустая строка молча
     * пропускаются, а ожидание кадра продолжается.
     *
     * @return array{id: int|null, event: string, data: string}|null
     */
    public function readEvent(float $timeout = 5.0): ?array
    {
        $id = null;
        $event = 'message';
        $dataLines = [];
        $sawFrame = false;

        while (true) {
            $line = $this->readLine($timeout);

            if ($line === null) {
                return null;
            }

            if ($line === '') {
                if ($sawFrame) {
                    break;
                }

                continue; // вторая строка комментария-пульса
            }

            if ($line[0] === ':') {
                continue; // комментарий SSE, не часть события
            }

            $sawFrame = true;

            if (str_starts_with($line, 'id:')) {
                $id = (int) trim(substr($line, 3));
            } elseif (str_starts_with($line, 'event:')) {
                $event = trim(substr($line, 6));
            } elseif (str_starts_with($line, 'data:')) {
                $dataLines[] = ltrim(substr($line, 5));
            }
        }

        return ['id' => $id, 'event' => $event, 'data' => implode("\n", $dataLines)];
    }

    private function readLine(float $timeout): ?string
    {
        if (! is_resource($this->socket)) {
            return null;
        }

        stream_set_timeout($this->socket, (int) ceil($timeout));
        $line = fgets($this->socket, 8192);
        $meta = stream_get_meta_data($this->socket);

        if ($line === false || $meta['eof'] || $meta['timed_out']) {
            return null;
        }

        return rtrim($line, "\r\n");
    }

    /** Обрывает соединение резко — то же самое, что обрыв связи у настоящего клиента. */
    public function disconnect(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }
}
