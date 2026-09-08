#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__.'/lib.php';

/**
 * Критерии приёмки 1 и 2: пятьдесят параллельных вебхуков «оплачено»
 * с ОДНИМ event_id по одному заказу.
 *
 * Ожидается ровно один факт выдачи и ровно один израсходованный ключ,
 * а сорок девять доставок не меняют ничего.
 */

Race::title('50 одновременных вебхуков с ОДНИМ event_id');

suppliers_ready();

// См. комментарий в race-double-click.php: у KEY-CS2-PRIME дешёвое
// предложение держит намеренно только одну единицу, и в общем прогоне
// make race-all её может забрать более ранний сценарий.
ensure_offer_available('KEY-CS2-PRIME', 1);

$order = create_order('KEY-CS2-PRIME');
$eventId = 'evt_'.bin2hex(random_bytes(10));

Race::step('заказ '.$order['id'].', событие '.$eventId);
Race::step('залп из 50 одновременных вебхуков');

$results = volley(array_fill(0, 50, webhook_request($order['id'], (int) $order['total_minor'], $eventId)));

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
Race::check(($outcomes['duplicate'] ?? 0) === 49, '49 повторов отсечены по event_id');
Race::check(($final['status'] ?? '') === 'delivered', 'заказ выдан', 'статус: '.($final['status'] ?? '?'));
Race::check(
    count($orderKeys) === 1,
    'за заказом закреплён ровно один ключ у поставщиков',
    'ключей: '.count($orderKeys).' → '.implode(', ', $orderKeys),
);
Race::check($issueEvents === 1, 'в истории заказа ровно один факт выдачи', 'фактов: '.$issueEvents);

exit(Race::summary());
