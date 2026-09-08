#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__.'/lib.php';

/**
 * Двойной клик по кнопке «Купить».
 *
 * Двадцать запросов на создание заказа уходят одновременно с ОДНИМ
 * ключом идемпотентности. Заказ должен получиться один.
 */

Race::title('Двойной клик «Купить»: 20 одновременных заказов с одним Idempotency-Key');

suppliers_ready();

// Задача 2 второго этапа переехала с бесконечного склада первого этапа на
// скромный сток предложения (у самого дешёвого предложения KEY-CS2-PRIME
// сидер держит намеренно только одну единицу — см. race-last-unit.php).
// Двадцать запросов ниже с ОДНИМ Idempotency-Key всё равно захватывают
// только одну единицу (это и есть сама проверка), но в общем прогоне
// make race-all предложение уже может стоять пустым после другого
// сценария — тогда сервер молча резолвил бы другое, более дорогое
// предложение позиции, что само по себе не ошибка, но лишает сценарий
// его демонстрационной точки: единица должна быть именно у дешёвого.
ensure_offer_available('KEY-CS2-PRIME', 1);

$key = 'idem_'.bin2hex(random_bytes(10));

Race::step('залп из 20 POST /api/orders, Idempotency-Key: '.$key);

$results = volley(array_fill(0, 20, [
    'method' => 'POST',
    'url' => base_url().'/api/orders',
    'json' => ['sku' => 'KEY-CS2-PRIME'],
    'headers' => ['Idempotency-Key: '.$key],
]));

$ids = [];
$created = 0;
$served = 0;

foreach ($results as $result) {
    if (in_array($result['status'], [200, 201], true)) {
        $served++;
    }
    if ($result['status'] === 201) {
        $created++;
    }
    if (is_array($result['body']) && isset($result['body']['id'])) {
        $ids[(string) $result['body']['id']] = true;
    }
}

$uniqueIds = array_keys($ids);

Race::check($served === 20, 'все 20 запросов обслужены без ошибок', 'успешных: '.$served);
Race::check(count($uniqueIds) === 1, 'создан ровно один заказ', 'уникальных id: '.count($uniqueIds).' → '.implode(', ', $uniqueIds));
Race::check($created === 1, 'ровно один ответ 201, остальные 200', '201: '.$created);

exit(Race::summary());
