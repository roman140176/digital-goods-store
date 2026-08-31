#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__.'/lib.php';

/**
 * Ответ об ошибке ПОСЛЕ того, как ключ уже закреплён.
 *
 * Сценарий, который ломает наивное правило «ответ получен, кода в нём нет —
 * значит кода не существует». Поставщик A настроен закрепить ключ за
 * request_id и ответить 503 (issue_then_error). Резервный поставщик при этом
 * полон: если магазин уйдёт к нему, за одним заказом окажется ДВА ключа.
 *
 * Правильное поведение: к резервному уходить только по однозначному
 * out_of_stock, а неясный ответ повторять тому же поставщику.
 */

Race::title('Поставщик выдал ключ и ответил ошибкой');

suppliers_ready(5);

$freeB = (int) (supplier_inventory('b')['free'] ?? 0);
Race::check($freeB > 0, 'у резервного поставщика есть свободные ключи', 'свободно у B: '.$freeB);

Race::step('поставщик A: закрепить ключ и ответить 503 (issue_then_error = 1)');
supplier_config('a', ['issue_then_error' => 1]);

$order = create_order('KEY-GTA5');

Race::step('заказ '.$order['id'].', оплачиваем и ждём исхода выдачи');
request('POST', base_url().'/api/dev/pay/'.$order['id']);

$final = wait_for_final($order['id'], 120);
$keys = keys_for_order($order['id']);

Race::check(
    ($final['status'] ?? '') === 'delivery_failed',
    'заказ в восстановимом состоянии, а не выдан резервным поставщиком',
    'статус: '.($final['status'] ?? '?').', ошибка: '.(string) ($final['delivery']['last_error'] ?? '—'),
);
Race::check(
    count($keys) === 1,
    'за заказом закреплён ровно один ключ',
    'ключей: '.count($keys).' → '.implode(', ', $keys),
);
Race::check(
    $keys !== [] && str_starts_with($keys[0], 'a:'),
    'ключ у поставщика A: к резервному по неясному ответу не уходим',
);
Race::check(
    ($final['delivered_by'] ?? null) === null && empty($final['code']),
    'код заказу не привязан, пока поставщик не ответил внятно',
);

Race::step('снимаем сбой у A и добиваем выдачу');
supplier_config('a', ['issue_then_error' => 0]);

$redeliver = admin_post('/admin/orders/'.$order['id'].'/redeliver');
Race::check($redeliver['status'] === 200, 'повторная выдача принята', 'HTTP '.$redeliver['status']);

$delivered = wait_for(static function () use ($order): bool {
    return (get_order($order['id'])['status'] ?? '') === 'delivered';
}, 90);

$after = get_order($order['id']);
$keysAfter = keys_for_order($order['id']);

Race::check($delivered, 'заказ выдан после снятия сбоя', 'статус: '.($after['status'] ?? '?'));
Race::check(
    ($after['delivered_by'] ?? '') === 'a',
    'код пришёл от того же поставщика A',
    'поставщик: '.(string) ($after['delivered_by'] ?? '?'),
);
Race::check(
    count($keysAfter) === 1,
    'суммарно за заказом один ключ: второй не сгорел',
    'ключей: '.count($keysAfter).' → '.implode(', ', $keysAfter),
);
Race::check(
    $keysAfter !== [] && $keysAfter[0] === 'a:'.(string) $after['code'],
    'выдан ровно тот ключ, который A закрепил до ответа с ошибкой',
);

exit(Race::summary());
