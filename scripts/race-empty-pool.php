#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__.'/lib.php';

/**
 * Критерий приёмки 4: остаток закончился в момент выдачи.
 *
 * Оплата прошла, ключей нет. Заказ обязан уйти в восстановимое состояние
 * без падения, а после пополнения повторная выдача — дать ровно один ключ,
 * даже если нажать «выдать повторно» десять раз одновременно.
 */

Race::title('Пустой склад, восстановление и десять одновременных повторных выдач');

suppliers_calm();

Race::step('опустошаем склады обоих поставщиков');
supplier_drain('a');
supplier_drain('b');

Race::check(total_free() === 0, 'свободных ключей не осталось', 'свободно: '.total_free());

$order = create_order('KEY-EFT');
Race::step('заказ '.$order['id'].', оплачиваем');

$paid = request('POST', base_url().'/api/dev/pay/'.$order['id']);
Race::check($paid['status'] === 200, 'оплата принята без ошибки', 'HTTP '.$paid['status']);

$final = wait_for_final($order['id'], 60);

Race::check(
    ($final['status'] ?? '') === 'out_of_stock',
    'заказ в восстановимом состоянии out_of_stock, а не в падении',
    'статус: '.($final['status'] ?? '?').', выдача: '.json_encode($final['delivery'] ?? null, JSON_UNESCAPED_UNICODE),
);
Race::check(($final['code'] ?? null) === null, 'кода нет, потому что выдавать было нечего');
Race::check(count(keys_for_order($order['id'])) === 0, 'за заказом не закреплено ни одного ключа');

Race::step('пополняем склад поставщика A');
supplier_restock('a', 3);

Race::step('залп из 10 одновременных повторных выдач');

$results = volley(array_fill(0, 10, [
    'method' => 'POST',
    'url' => base_url().'/admin/orders/'.$order['id'].'/redeliver?token='.urlencode(admin_token()),
    'json' => [],
]));

$accepted = count(array_filter($results, static fn (array $r): bool => $r['status'] === 200));

$delivered = wait_for(static function () use ($order): bool {
    return (get_order($order['id'])['status'] ?? '') === 'delivered';
}, 60);

$after = get_order($order['id']);
$orderKeys = keys_for_order($order['id']);

Race::check($accepted === 10, 'все 10 нажатий обработаны без ошибок', 'принято: '.$accepted);
Race::check($delivered, 'заказ выдан после пополнения', 'статус: '.($after['status'] ?? '?'));
Race::check(! empty($after['code']), 'код привязан к заказу', 'код: '.(string) ($after['code'] ?? '—'));
Race::check(
    count($orderKeys) === 1,
    'на десять одновременных нажатий израсходован ровно один ключ',
    'ключей за заказом: '.count($orderKeys).' → '.implode(', ', $orderKeys),
);
Race::check(
    in_array('a:'.(string) $after['code'], $orderKeys, true),
    'выданный заказу код — это тот самый закреплённый ключ',
);

exit(Race::summary());
