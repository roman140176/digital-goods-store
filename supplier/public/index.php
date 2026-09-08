<?php

declare(strict_types=1);

/**
 * Заглушка поставщика цифровых товаров.
 *
 * Реализует контракт из ТЗ: POST /issue выдаёт код по request_id и обязан
 * вернуть тот же код на повтор. Умеет управляемо падать и подвисать,
 * чтобы воспроизводить сбойные сценарии.
 *
 * Один и тот же образ поднимается двумя инстансами (A и B) с разными
 * складами и разными профилями отказов.
 */

require __DIR__ . '/../src/Db.php';
require __DIR__ . '/../src/Chaos.php';
require __DIR__ . '/../src/Inventory.php';

const SUPPLIER_ID = 'supplier-';

function json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
}

function supplierId(): string
{
    return getenv('SUPPLIER_ID') ?: 'a';
}

try {
    Db::ensureSchema();

    // Первый запуск: засеваем свою долю пула из ТЗ.
    $offset = (int) (getenv('KEYS_OFFSET') ?: 0);
    $limit = (int) (getenv('KEYS_LIMIT') ?: 25);
    Inventory::seed(array_slice(require __DIR__ . '/../keys.php', $offset, $limit));
} catch (Throwable $e) {
    json(['status' => 'error', 'reason' => 'storage_unavailable', 'detail' => $e->getMessage()], 503);
}

$path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($path === '/health') {
    json(['status' => 'ok', 'supplier' => supplierId()]);
}

// Какие ключи закреплены за конкретным request_id: этим проверки
// доказывают однократность по конкретному заказу, а не «в среднем».
if (str_starts_with($path, '/issued/') && $method === 'GET') {
    json(['supplier' => supplierId()] + Inventory::issuedFor(substr($path, strlen('/issued/'))));
}

if ($path === '/inventory' && $method === 'GET') {
    json(['supplier' => supplierId()] + Inventory::stats());
}

if ($path === '/config') {
    if ($method === 'POST') {
        Chaos::set(body());
    }

    json(['supplier' => supplierId(), 'config' => Chaos::all()]);
}

if ($path === '/restock' && $method === 'POST') {
    // Верхняя граница — не защита от нагрузки (батч в Inventory::restock
    // справляется и с большим количеством одним запросом), а страховка от
    // одной лишней цифры в запросе синхронизации складов под объёмный каталог.
    $count = max(1, min(50000, (int) (body()['count'] ?? 1)));
    json(['supplier' => supplierId(), 'added' => Inventory::restock($count)] + Inventory::stats());
}

if ($path === '/reset' && $method === 'POST') {
    // Полный сброс склада к исходному пулу из ТЗ и спокойный профиль отказов.
    Db::conn()->exec('DELETE FROM keys');
    Inventory::clearSeedFlag();
    Chaos::set(['error_rate' => 0, 'timeout_rate' => 0, 'issue_then_timeout' => 0, 'timeout_seconds' => 10]);

    $seeded = Inventory::seed(array_slice(
        require __DIR__ . '/../keys.php',
        (int) (getenv('KEYS_OFFSET') ?: 0),
        (int) (getenv('KEYS_LIMIT') ?: 25),
    ));

    json(['supplier' => supplierId(), 'seeded' => $seeded] + Inventory::stats());
}

if ($path === '/drain' && $method === 'POST') {
    json(['supplier' => supplierId(), 'removed' => Inventory::drain()] + Inventory::stats());
}

if ($path === '/issue' && $method === 'POST') {
    $payload = body();
    $requestId = trim((string) ($payload['request_id'] ?? ''));

    if ($requestId === '') {
        json(['status' => 'error', 'reason' => 'request_id_required'], 422);
    }

    $config = Chaos::all();

    // Сбои разыгрываются ВОКРУГ выдачи, в том числе на повторах: сломанный
    // поставщик имеет право не донести уже закреплённый ключ. Второй ключ при
    // этом невозможен — за это отвечает идемпотентность Inventory::issue по
    // request_id. Именно поэтому ответ об ошибке не доказывает отсутствие кода.

    // Отказ до выдачи: код не тронут, повтор безопасен.
    if (Chaos::rolls($config['error_rate'])) {
        json(['status' => 'error', 'reason' => 'supplier_error'], 503);
    }

    // Подвис до выдачи: клиент получит таймаут, код не тронут.
    if (Chaos::rolls($config['timeout_rate'])) {
        usleep((int) ($config['timeout_seconds'] * 1_000_000));
        json(['status' => 'error', 'reason' => 'supplier_timeout'], 504);
    }

    $result = Inventory::issue(
        $requestId,
        isset($payload['sku']) ? (string) $payload['sku'] : null,
        isset($payload['order_id']) ? (string) $payload['order_id'] : null,
    );

    if ($result['status'] === 'error') {
        json(['status' => 'error', 'reason' => $result['reason']], 409);
    }

    // Ловушка таймаута: код УЖЕ выдан и зафиксирован, но ответ не дойдёт.
    // Клиент обязан повторить с тем же request_id, а не уйти на резервного
    // поставщика, иначе будет израсходовано два ключа вместо одного.
    if (Chaos::rolls($config['issue_then_timeout'])) {
        usleep((int) ($config['timeout_seconds'] * 1_000_000));
    }

    // Та же ловушка, но с ОТВЕТОМ об ошибке: ключ закреплён за request_id,
    // а клиент видит 503. Ответ об ошибке не доказывает, что кода нет.
    if (Chaos::rolls($config['issue_then_error'])) {
        json(['status' => 'error', 'reason' => 'supplier_error'], 503);
    }

    json([
        'status' => 'ok',
        'request_id' => $requestId,
        'code' => $result['code'],
        'supplier' => supplierId(),
        'reused' => $result['reused'],
    ]);
}

json(['status' => 'error', 'reason' => 'not_found', 'path' => $path], 404);
