#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__.'/lib.php';

/**
 * Критерий приёмки 3: вебхук пришёл раньше создания заказа или не по порядку.
 *
 * Проверяются три ситуации:
 *   A — «оплачено» приходит до того, как заказ появился;
 *   B — «не оплачено» приходит после «оплачено» со старшей меткой времени;
 *   C — «оплачено» приходит повторно, с устаревшей меткой времени.
 */

Race::title('Вебхуки не по порядку и раньше создания заказа');

suppliers_ready();

// ---------------------------------------------------------------- A
Race::step('A. вебхук «оплачено» приходит ДО создания заказа');

$orderId = 'ord_early_'.bin2hex(random_bytes(6));

// Сумма читается у предложения ЖИВЬЁМ, а не хардкодом: /api/dev/orders ниже
// резолвит заказ по голому sku (bestOfferFor — самое дешёвое АКТИВНОЕ
// предложение со свободной единицей), и после задачи 2 второго этапа это
// не обязательно тот же самый offer_id и та же цена, что были на момент
// написания сценария (см. race-double-click.php про скудный сток
// KEY-CS2-PRIME). ensure_offer_available подкачивает именно ТО предложение,
// которое затем резолвит bestOfferFor, — цена берётся из того же ответа,
// поэтому сумма вебхука ниже гарантированно совпадёт с amount_minor заказа,
// который появится позже.
$hotOffer = ensure_offer_available('KEY-CS2-PRIME', 1);
$totalMinor = (int) $hotOffer['price_minor'];

$early = request(
    'POST',
    base_url().'/api/webhook/payment',
    webhook_request($orderId, $totalMinor, 'evt_'.bin2hex(random_bytes(8)))['json'],
);

Race::check($early['status'] === 200, 'вебхук на несуществующий заказ не уронил сервис', 'HTTP '.$early['status']);
Race::check(
    is_array($early['body']) && ($early['body']['outcome'] ?? '') === 'parked_no_order',
    'событие припарковано до появления заказа',
    json_encode($early['body'], JSON_UNESCAPED_UNICODE),
);

$created = request('POST', base_url().'/api/dev/orders', [
    'id' => $orderId,
    'sku' => 'KEY-CS2-PRIME',
], ['Idempotency-Key: '.$orderId]);

Race::check($created['status'] === 201, 'заказ создан с тем же идентификатором', 'HTTP '.$created['status']);

$final = wait_for_final($orderId);

Race::check(($final['status'] ?? '') === 'delivered', 'припаркованная оплата применилась, заказ выдан', 'статус: '.($final['status'] ?? '?'));
Race::check(count(keys_for_order($orderId)) === 1, 'за заказом закреплён ровно один ключ');

// ---------------------------------------------------------------- B
Race::step('B. «не оплачено» со СТАРШЕЙ меткой приходит после «оплачено»');

ensure_offer_available('KEY-GTA5', 1);
$order = create_order('KEY-GTA5');
$now = time();

$paid = request('POST', base_url().'/api/webhook/payment',
    webhook_request($order['id'], (int) $order['total_minor'], 'evt_'.bin2hex(random_bytes(8)), 'paid', gmdate('c', $now))['json']);

$stale = request('POST', base_url().'/api/webhook/payment',
    webhook_request($order['id'], (int) $order['total_minor'], 'evt_'.bin2hex(random_bytes(8)), 'failed', gmdate('c', $now - 120))['json']);

$staleOutcome = is_array($stale['body']) ? (string) ($stale['body']['outcome'] ?? '') : '';

Race::check(is_array($paid['body']) && ($paid['body']['outcome'] ?? '') === 'applied', 'оплата применена');
Race::check($staleOutcome === 'stale', 'устаревшее «не оплачено» отброшено как stale', 'исход: '.$staleOutcome);

$final = wait_for_final($order['id']);

Race::check(($final['status'] ?? '') === 'delivered', 'заказ всё равно выдан', 'статус: '.($final['status'] ?? '?'));
Race::check(count(keys_for_order($order['id'])) === 1, 'за заказом закреплён ровно один ключ');

// ---------------------------------------------------------------- C
Race::step('C. повторное «оплачено» с устаревшей меткой уже после выдачи');

$keysBefore = count(keys_for_order($order['id']));

$late = request('POST', base_url().'/api/webhook/payment',
    webhook_request($order['id'], (int) $order['total_minor'], 'evt_'.bin2hex(random_bytes(8)), 'paid', gmdate('c', $now - 30))['json']);

$lateOutcome = is_array($late['body']) ? (string) ($late['body']['outcome'] ?? '') : '';
$after = get_order($order['id']);

Race::check($late['status'] === 200, 'запоздавшее событие принято без ошибки', 'HTTP '.$late['status']);
Race::check(
    in_array($lateOutcome, ['stale', 'ignored_terminal'], true),
    'запоздавшее событие не изменило выданный заказ',
    'исход: '.$lateOutcome,
);
Race::check(($after['code'] ?? null) === ($final['code'] ?? null), 'код заказа не изменился');
Race::check(
    count(keys_for_order($order['id'])) === $keysBefore,
    'ни одного лишнего ключа за заказом не появилось',
    'ключей: '.count(keys_for_order($order['id'])),
);

exit(Race::summary());
