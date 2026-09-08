#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__.'/lib.php';

/**
 * Критерий приёмки 1.3: цена меняется в момент оплаты.
 *
 * Заказ фиксирует цену на момент брони (amount_minor/total_minor). Админка
 * тем временем может поменять цену предложения — сервер обязан заметить это
 * ДО того, как деньги спишутся по устаревшей цене (см. 5.3 спеки,
 * PaymentSimulatorController::pay): если offers.price_minor разошлась с
 * orders.amount_minor, оплата отвергается 409 price_changed вместо того,
 * чтобы списать неправильную сумму.
 *
 * Смена цены и оплата запускаются ОДНИМ залпом (volley — настоящий
 * curl_multi, оба запроса уходят одновременно), 30 повторов на предложении с
 * заведомо достаточным остатком (сама цена — предмет теста, не остаток).
 *
 * /api/dev/pay сам почти всегда отвечает 200 — это лишь подтверждение, что
 * эмулятор ПОСЛАЛ вебхук. Guard цены живёт РАНЬШЕ вебхука, в самом
 * /api/dev/pay, и именно поэтому 409 price_changed приходит внешним кодом
 * ответа (см. PaymentSimulatorController::pay) — в отличие от исхода самого
 * вебхука, который лежит вложенным полем webhook_status (см.
 * race-reservation-expiry.php, где спутать эти два кода ответа стоило
 * ложного прогона).
 */

Race::title('Смена цены в админке против оплаты: 30 повторов');

suppliers_ready(35);

$offer = ensure_offer_available('GIFT-XBOX-1500', 40);
$offerId = (int) $offer['offer_id'];
$priceCounter = (int) $offer['price_minor'];

Race::step('предложение '.$offerId.' (GIFT-XBOX-1500), 30 повторов «заказ → одновременно смена цены и оплата»');

$outcomes = ['paid' => 0, 'price_changed' => 0, 'other' => 0];
$http500 = 0;
$staleAmountApplied = 0;
$retroactiveTotalChanges = 0;
$mismatchedRejection = 0;
$dbCrossCheckMismatches = 0;

for ($i = 1; $i <= 30; $i++) {
    $order = create_order_for_offer($offerId, null, 'race_price_'.$i.'_'.bin2hex(random_bytes(6)));
    $orderId = (string) ($order['id'] ?? '');
    $totalAtCreation = (int) ($order['total_minor'] ?? -1);

    if ($orderId === '') {
        $outcomes['other']++;

        continue;
    }

    // Новая цена — заведомо другое число, чтобы 409 (если случится) можно
    // было проверить по значению, а не только по факту отказа.
    $priceCounter += 100;
    $newPrice = $priceCounter;

    $priceRequest = [
        'method' => 'POST',
        'url' => base_url().'/admin/offers/'.$offerId.'/price?token='.urlencode(admin_token()),
        'json' => ['price_minor' => $newPrice],
    ];
    $payRequest = ['method' => 'POST', 'url' => base_url().'/api/dev/pay/'.$orderId];

    // Каждый третий повтор — цена меняется ЗАВЕДОМО раньше оплаты (ждём
    // ответ админки, потом шлём оплату), а не тем же залпом. У guard'а в
    // /api/dev/pay работа простая — одно сравнение price_minor с
    // amount_minor, — а у оплаты она куда тяжелее (вставка payment_events,
    // блокировка заказа, продажа единицы), и в честном одновременном залпе
    // оплата почти всегда успевает раньше, чем цена вообще закоммитится:
    // без принудительного подмножества 409 price_changed мог не случиться
    // ни разу за все 30 повторов, и вторая ветка требования 1.3 осталась бы
    // непроверенной (см. тот же приём в race-reservation-expiry.php).
    if ($i % 3 === 0) {
        $priceResult = request($priceRequest['method'], $priceRequest['url'], $priceRequest['json']);
        $payResult = request($payRequest['method'], $payRequest['url']);
    } else {
        [$priceResult, $payResult] = volley([$priceRequest, $payRequest]);
    }

    if ($priceResult['status'] >= 500) {
        $http500++;
    }

    if ($payResult['status'] === 409) {
        $outcomes['price_changed']++;

        $body = is_array($payResult['body']) ? $payResult['body'] : [];

        if (($body['reason'] ?? '') !== 'price_changed' || (int) ($body['current_price_minor'] ?? -1) !== $newPrice) {
            $mismatchedRejection++;
        }

        continue;
    }

    $webhookStatus = (int) ($payResult['body']['webhook_status'] ?? $payResult['status']);

    if ($payResult['status'] >= 500 || $webhookStatus >= 500) {
        $http500++;

        continue;
    }

    $final = get_order($orderId);
    $status = (string) ($final['status'] ?? '');

    if (! in_array($status, ['paid', 'delivering', 'delivered'], true)) {
        $outcomes['other']++;

        continue;
    }

    $outcomes['paid']++;

    // Заказ фиксирует цену на момент брони — более поздняя смена цены не
    // должна задним числом переписать уже принятую сумму.
    if ((int) ($final['total_minor'] ?? -1) !== $totalAtCreation) {
        $retroactiveTotalChanges++;
    }

    // Проверка ПО БАЗЕ, не по ответу API: журнал payment_events — источник
    // истины для «применённое событие никогда не расходится с total_minor
    // заказа» (критерий приёмки), а не только зеркало того, что сам же
    // эмулятор перед отправкой прочитал из order.total_minor.
    $appliedAmount = db_value(
        "SELECT amount_minor FROM payment_events WHERE order_id = ? AND outcome = 'applied' ORDER BY received_at DESC LIMIT 1",
        [$orderId],
    );
    $orderTotalNow = (int) db_value('SELECT total_minor FROM orders WHERE id = ?', [$orderId]);

    if ($appliedAmount === null) {
        $staleAmountApplied++;
    } elseif ((int) $appliedAmount !== $orderTotalNow) {
        $dbCrossCheckMismatches++;
    }
}

Race::check($http500 === 0, 'ни один из 60 конкурентных вызовов (смена цены + оплата × 30) не завершился 500', 'проблемных: '.$http500);
Race::check(
    $outcomes['other'] === 0,
    'исход каждого повтора — один из двух допустимых (оплата прошла или отвергнута price_changed)',
    'прочих исходов: '.$outcomes['other'].' ('.json_encode($outcomes, JSON_UNESCAPED_UNICODE).')',
);
Race::check(
    $mismatchedRejection === 0,
    'каждый 409 price_changed называет ТУ САМУЮ новую цену, что и была выставлена одновременно с ним',
    'несовпадений: '.$mismatchedRejection,
);
Race::check(
    $retroactiveTotalChanges === 0,
    'цена заказа зафиксирована на момент брони — более поздняя смена цены задним числом её не переписывает (1.3)',
    'заказов с изменившимся total_minor: '.$retroactiveTotalChanges,
);
Race::check(
    $staleAmountApplied === 0,
    'у каждого успешно оплаченного заказа в payment_events есть ровно одна применённая запись',
    'заказов без applied-события: '.$staleAmountApplied,
);
Race::check(
    $dbCrossCheckMismatches === 0,
    'никогда не бывает применённого события с суммой, не равной total_minor заказа (сверка по payment_events)',
    'расхождений: '.$dbCrossCheckMismatches,
);

Race::step(sprintf(
    'распределение исходов за 30 повторов: оплачено=%d, отвергнуто по цене=%d',
    $outcomes['paid'],
    $outcomes['price_changed'],
));
Race::check(
    $outcomes['paid'] > 0,
    'ветка «оплата успела пройти по актуальной на тот момент цене» реально наблюдалась',
);
Race::check(
    $outcomes['price_changed'] > 0,
    'ветка «цену успели сменить раньше оплаты» реально наблюдалась — не только иллюстрация',
    'если это ноль — залп нужно перебалансировать по времени',
);

exit(Race::summary());
