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

    if (total_free() < $minFree) {
        supplier_restock('a', $minFree * 2);
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
