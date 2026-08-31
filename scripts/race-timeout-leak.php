#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__.'/lib.php';

/**
 * Ловушка таймаута из контракта поставщика.
 *
 * Поставщик A настроен выдавать код и НЕ отвечать: ключ израсходован,
 * подтверждения нет. Правильное поведение — не уходить к резервному
 * поставщику (это сожгло бы второй ключ), а оставить заказ в восстановимом
 * состоянии и повторять тому же поставщику с тем же request_id.
 */

Race::title('Ловушка таймаута: поставщик выдал код, но ответ не дошёл');

suppliers_ready();

Race::step('поставщик A: выдавать код и не отвечать (issue_then_timeout = 1)');
supplier_config('a', ['issue_then_timeout' => 1, 'timeout_seconds' => 8]);

$order = create_order('KEY-CS2-PRIME');

Race::step('заказ '.$order['id'].', оплачиваем и ждём исхода выдачи');
request('POST', base_url().'/api/dev/pay/'.$order['id']);

$final = wait_for_final($order['id'], 120);
$keysAfterLeak = keys_for_order($order['id']);

Race::check(
    ($final['status'] ?? '') === 'delivery_failed',
    'заказ ушёл в восстановимое состояние, а не к резервному поставщику',
    'статус: '.($final['status'] ?? '?').', ошибка: '.(string) ($final['delivery']['last_error'] ?? '—'),
);
Race::check(
    count($keysAfterLeak) === 1,
    'у поставщика A закреплён ровно один ключ, у резервного — ни одного',
    'ключей за заказом: '.count($keysAfterLeak).' → '.implode(', ', $keysAfterLeak),
);
Race::check(
    $keysAfterLeak !== [] && str_starts_with($keysAfterLeak[0], 'a:'),
    'ключ закреплён именно за поставщиком A',
);

Race::step('снимаем сбой у A и добиваем выдачу');
supplier_config('a', ['issue_then_timeout' => 0]);

$redeliver = admin_post('/admin/orders/'.$order['id'].'/redeliver');
Race::check($redeliver['status'] === 200, 'повторная выдача принята', 'HTTP '.$redeliver['status']);

$delivered = wait_for(static function () use ($order): bool {
    return (get_order($order['id'])['status'] ?? '') === 'delivered';
}, 90);

$after = get_order($order['id']);
$orderKeys = keys_for_order($order['id']);

Race::check($delivered, 'заказ выдан после снятия сбоя', 'статус: '.($after['status'] ?? '?'));
Race::check(! empty($after['code']), 'код привязан к заказу', 'код: '.(string) ($after['code'] ?? '—'));
Race::check(
    ($after['delivered_by'] ?? '') === 'a',
    'код получен от того же поставщика A, который «потерял» ответ',
    'поставщик: '.(string) ($after['delivered_by'] ?? '?'),
);
Race::check(
    count($orderKeys) === 1,
    'суммарно за заказом закреплён ровно один ключ, задвоения нет',
    'ключей: '.count($orderKeys).' → '.implode(', ', $orderKeys),
);
Race::check(
    $orderKeys !== [] && $orderKeys[0] === 'a:'.(string) $after['code'],
    'выдан ровно тот код, который A закрепил при потерянном ответе',
);

exit(Race::summary());
