#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__.'/lib.php';

/**
 * Критерий приёмки 5: промокод с лимитом N под параллельными запросами
 * применяется не более N раз, а скидку считает сервер.
 *
 * Проверяются два кода из ТЗ: ONCEONLY (лимит 1) и LIMIT3 (лимит 3).
 * По каждому уходит залп из пятидесяти одновременных заказов.
 *
 * Третий блок закрывает обход лимита не гонкой, а последовательностью:
 * отказ оплаты не должен возвращать использование в лимит, потому что
 * платёж может подтвердиться позже и заказ со скидкой оживёт.
 */

Race::title('Лимит промокодов под параллельными заказами');

suppliers_ready();

$scenarios = [
    'ONCEONLY' => 'KEY-CS2-PRIME',
    'LIMIT3' => 'KEY-GTA5',
];

foreach ($scenarios as $code => $sku) {
    $before = promo_state($code);

    if ($before === []) {
        Race::check(false, "промокод {$code} найден в справочнике");

        continue;
    }

    $remaining = (int) $before['remaining'];
    $maxUses = (int) $before['max_uses'];

    Race::step(sprintf(
        '%s: лимит %d, использовано %d, доступно %d — залп из 50 заказов',
        $code,
        $maxUses,
        (int) $before['used_count'],
        $remaining,
    ));

    $requests = [];
    for ($i = 0; $i < 50; $i++) {
        $requests[] = [
            'method' => 'POST',
            'url' => base_url().'/api/orders',
            'json' => ['sku' => $sku, 'promo_code' => $code],
            'headers' => ['Idempotency-Key: promo_'.$code.'_'.bin2hex(random_bytes(8)).'_'.$i],
        ];
    }

    $results = volley($requests);

    $applied = 0;
    $rejected = 0;
    $discountsPositive = 0;
    $other = [];

    foreach ($results as $result) {
        if ($result['status'] === 201) {
            $applied++;
            if ((int) ($result['body']['discount_minor'] ?? 0) > 0) {
                $discountsPositive++;
            }

            continue;
        }

        if ($result['status'] === 422 && is_array($result['body']) && ($result['body']['reason'] ?? '') === 'limit_reached') {
            $rejected++;

            continue;
        }

        $other[] = $result['status'];
    }

    $after = promo_state($code);

    Race::check(
        $applied === $remaining,
        sprintf('%s: применён ровно %d раз', $code, $remaining),
        'принято: '.$applied.', отказано по лимиту: '.$rejected.', прочее: '.json_encode($other),
    );
    Race::check(
        $applied + $rejected === 50,
        $code.': все 50 запросов получили осмысленный ответ',
    );
    Race::check(
        (int) $after['used_count'] === $maxUses,
        sprintf('%s: счётчик использований равен лимиту (%d)', $code, $maxUses),
        'счётчик: '.(int) $after['used_count'],
    );
    Race::check(
        (int) $after['used_count'] <= $maxUses,
        $code.': лимит не превышен ни на одну единицу',
    );
    Race::check(
        $discountsPositive === $applied,
        $code.': скидку по каждому принятому заказу посчитал сервер',
        'заказов со скидкой: '.$discountsPositive.' из '.$applied,
    );
}

// --- Лимит не обходится через отказ оплаты и позднее подтверждение ---

$code = 'GG500';
$state = promo_state($code);
$usedBefore = (int) ($state['used_count'] ?? 0);

Race::step(sprintf(
    '%s: отказ оплаты → позднее подтверждение (использовано %d из %d)',
    $code,
    $usedBefore,
    (int) ($state['max_uses'] ?? 0),
));

$order = create_order('KEY-CS2-PRIME', $code);
$orderId = (string) ($order['id'] ?? '');
$discount = (int) ($order['discount_minor'] ?? 0);
$total = (int) ($order['total_minor'] ?? 0);

Race::check($orderId !== '' && $discount > 0, $code.': заказ со скидкой создан', 'скидка: '.$discount);
Race::check(
    (int) promo_state($code)['used_count'] === $usedBefore + 1,
    $code.': использование занято при создании заказа',
);

// Отказ приходит со старшей меткой, подтверждение — со свежей: порядок
// определяет платёжная система, и подтверждение обязано победить.
$failedAt = gmdate('c', time() - 60);
request('POST', base_url().'/api/webhook/payment', webhook_request(
    $orderId,
    $total,
    'evt_promo_fail_'.bin2hex(random_bytes(6)),
    'failed',
    $failedAt,
)['json']);

$afterFail = get_order($orderId);

Race::check(
    ($afterFail['status'] ?? '') === 'payment_failed',
    $code.': отказ оплаты применён',
    'статус: '.($afterFail['status'] ?? '?'),
);
Race::check(
    (int) promo_state($code)['used_count'] === $usedBefore + 1,
    $code.': отказ оплаты НЕ вернул использование в лимит',
    'счётчик: '.(int) promo_state($code)['used_count'],
);

request('POST', base_url().'/api/webhook/payment', webhook_request(
    $orderId,
    $total,
    'evt_promo_paid_'.bin2hex(random_bytes(6)),
    'paid',
    gmdate('c'),
)['json']);

$resurrected = wait_for_final($orderId, 90);

Race::check(
    in_array($resurrected['status'] ?? '', ['paid', 'delivering', 'delivered'], true),
    $code.': оплата, подтверждённая позже, не потеряна',
    'статус: '.($resurrected['status'] ?? '?'),
);
Race::check(
    (int) ($resurrected['discount_minor'] ?? -1) === $discount,
    $code.': скидка воскресшего заказа сохранена',
);

$usedAfter = (int) promo_state($code)['used_count'];

Race::check(
    $usedAfter === $usedBefore + 1,
    $code.': за весь цикл код применён ровно один раз',
    'было: '.$usedBefore.', стало: '.$usedAfter,
);
Race::check(
    $usedAfter <= (int) promo_state($code)['max_uses'],
    $code.': лимит не превышен',
);

exit(Race::summary());
