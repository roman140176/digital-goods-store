#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__.'/lib.php';

/**
 * Критерий приёмки 1 в более злом варианте: пятьдесят параллельных
 * вебхуков «оплачено» по одному заказу, но с РАЗНЫМИ event_id.
 *
 * Дедупликация по event_id здесь не спасает — работает только блокировка
 * заказа и машина состояний: выйти из created может ровно одно событие.
 */

Race::title('50 одновременных вебхуков с РАЗНЫМИ event_id по одному заказу');

suppliers_ready();

// См. комментарий в race-double-click.php про KEY-CS2-PRIME: у KEY-GTA5
// такого намеренно скудного предложения нет, но в общем прогоне
// make race-all её самое дешёвое предложение тоже может быть уже раскуплено
// более ранним сценарием (например, залпом race-promo-limit.php) — тогда
// заказ создастся на другом, более дорогом предложении, что само по себе не
// ошибка, но нет причины оставлять сценарий заложником чужого остатка.
ensure_offer_available('KEY-GTA5', 1);

$order = create_order('KEY-GTA5');

Race::step('заказ '.$order['id']);
Race::step('залп из 50 вебхуков с уникальными event_id');

$base = time();
$requests = [];
for ($i = 0; $i < 50; $i++) {
    $requests[] = webhook_request(
        $order['id'],
        (int) $order['total_minor'],
        'evt_'.bin2hex(random_bytes(8)).'_'.$i,
        'paid',
        gmdate('c', $base + $i),
    );
}

$results = volley($requests);

$http200 = 0;
$outcomes = [];

foreach ($results as $result) {
    if ($result['status'] === 200) {
        $http200++;
    }
    $outcome = is_array($result['body']) ? (string) ($result['body']['outcome'] ?? 'none') : 'none';
    $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
}

$final = wait_for_final($order['id']);
$orderKeys = keys_for_order($order['id']);
$issueEvents = count(array_filter(
    $final['history'] ?? [],
    static fn (array $row): bool => $row['action'] === 'code_issued',
));

Race::check($http200 === 50, 'все 50 вебхуков получили быстрый 200', 'двухсоток: '.$http200);
Race::check(($outcomes['applied'] ?? 0) === 1, 'применено ровно одно событие', json_encode($outcomes, JSON_UNESCAPED_UNICODE));
Race::check(($final['status'] ?? '') === 'delivered', 'заказ выдан', 'статус: '.($final['status'] ?? '?'));
Race::check(
    count($orderKeys) === 1,
    'за заказом закреплён ровно один ключ у поставщиков',
    'ключей: '.count($orderKeys).' → '.implode(', ', $orderKeys),
);
Race::check($issueEvents === 1, 'в истории заказа ровно один факт выдачи', 'фактов: '.$issueEvents);

exit(Race::summary());
