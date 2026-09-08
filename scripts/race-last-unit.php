#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__.'/lib.php';

/**
 * Критерии приёмки 2.1, 2.2, 2.3: покупка последней единицы наперегонки.
 *
 * Двадцать параллельных POST /api/orders на предложение с ОДНОЙ свободной
 * единицей, разные ключи идемпотентности. У дешёвого предложения
 * KEY-CS2-PRIME в базовом сиде ровно одна единица уже по построению сида
 * (см. OfferSeeder) — ровно это предложение и описано в брифе демонстрации.
 * leave-one вызывается всё равно: сценарий не должен зависеть от того, что
 * никто до него не тронул эту же единицу (в общем прогоне make race-all
 * несколько сценариев подряд конкурируют за один и тот же товар).
 */

Race::title('Последняя единица: 20 одновременных покупателей на одно предложение');

suppliers_ready();

$hot = cheapest_offer('KEY-CS2-PRIME');
$hotOfferId = (int) $hot['offer_id'];

// leave-one наводит порядок только среди единиц в состоянии available — а
// в общем прогоне make race-all по этому же предложению уже могли пройти
// сценарии, которые заказ создают, но никогда не оплачивают (например,
// race-double-click.php: заказ намеренно висит неоплаченным всю проверку).
// Их единицы остаются в состоянии reserved до TTL брони (300 c по
// умолчанию — дольше всего прогона), leave-one их не видит и не тронет, и
// «ровно одна единица» после него была бы правдой только для available, а
// не для физического числа строк. Снимаем такие брони явно — тем же
// приёмом, что и в race-reservation-expiry.php: expire по каждому чужому
// заказу, затем один синхронный release.
$staleReservations = db_rows(
    "SELECT DISTINCT reserved_order_id FROM stock_units WHERE offer_id = ? AND state = 'reserved'",
    [$hotOfferId],
);

foreach ($staleReservations as $row) {
    request('POST', base_url().'/api/dev/reservations/'.$row['reserved_order_id'].'/expire');
}

if ($staleReservations !== []) {
    artisan_run('reservations:release');
}

Race::step('оставляем у предложения '.$hotOfferId.' (цена '.$hot['price_minor'].') ровно одну свободную единицу');
admin_post('/admin/offers/'.$hotOfferId.'/leave-one');

// Альтернатива для 409 sold_out обязана реально существовать и иметь
// остаток — иначе проверка «alternative.available > 0» была бы удачей
// сидера, а не гарантией сценария. Второе по цене предложение позиции
// подкачивается явно, отдельно от leave-one на первом.
$offers = offers_for('KEY-CS2-PRIME');
$alternativeOfferId = null;

foreach ($offers as $offer) {
    if ((int) $offer['offer_id'] === $hotOfferId) {
        continue;
    }

    $alternativeOfferId = (int) $offer['offer_id'];

    if ((int) $offer['available'] < 1) {
        admin_post('/admin/offers/'.$alternativeOfferId.'/stock', ['units' => 3]);
    }

    break;
}

Race::check($alternativeOfferId !== null, 'у позиции KEY-CS2-PRIME есть второе активное предложение для альтернативы');

// Промокод в этом залпе не участвует вовсе — критерий 5 такого прогона
// требует, чтобы ничей лимит не пострадал случайно.
$promoBefore = promo_state('GG500');

// «Заказов ровно один» проверяется ДЕЛЬТОЙ, а не абсолютным счётчиком:
// сценарий перезапускаем не только внутри одного make race-all, но и вручную
// (make race-last-unit) — предыдущие прогоны на этом же offer_id уже оставили
// свои заказы в базе, и абсолютный count(*) считал бы их чужую историю.
$ordersBefore = (int) db_value('SELECT count(*) FROM orders WHERE offer_id = ?', [$hotOfferId]);

Race::step('залп из 20 POST /api/orders на offer_id='.$hotOfferId.', разные Idempotency-Key');

$requests = [];
for ($i = 0; $i < 20; $i++) {
    $requests[] = [
        'method' => 'POST',
        'url' => base_url().'/api/orders',
        'json' => ['offer_id' => $hotOfferId],
        'headers' => ['Idempotency-Key: race_last_unit_'.bin2hex(random_bytes(8)).'_'.$i],
    ];
}

$results = volley($requests);

$created = 0;
$soldOut = 0;
$winnerId = null;
$badAlternative = 0;

foreach ($results as $result) {
    if ($result['status'] === 201) {
        $created++;
        $winnerId = (string) ($result['body']['id'] ?? '');

        continue;
    }

    if ($result['status'] === 409 && is_array($result['body']) && ($result['body']['reason'] ?? '') === 'sold_out') {
        $soldOut++;
        $alternative = $result['body']['alternative'] ?? null;

        if (! is_array($alternative) || (int) ($alternative['available'] ?? 0) < 1) {
            $badAlternative++;
        }
    }
}

Race::check($created === 1, 'ровно один ответ 201', '201: '.$created);
Race::check($soldOut === 19, 'остальные 19 получили 409 sold_out', '409 sold_out: '.$soldOut);
Race::check($badAlternative === 0, 'у каждого отказа непустая альтернатива с available > 0', 'без валидной альтернативы: '.$badAlternative);
Race::check($winnerId !== null && $winnerId !== '', 'победитель определён', 'id: '.(string) $winnerId);

$reservedRows = db_rows(
    "SELECT id, reserved_order_id FROM stock_units WHERE offer_id = ? AND state = 'reserved'",
    [$hotOfferId],
);
$newOrders = (int) db_value('SELECT count(*) FROM orders WHERE offer_id = ?', [$hotOfferId]) - $ordersBefore;

Race::check(
    count($reservedRows) === 1,
    'в stock_units предложения ровно одна строка в состоянии reserved',
    'строк: '.count($reservedRows),
);
Race::check(
    $reservedRows !== [] && $reservedRows[0]['reserved_order_id'] === $winnerId,
    'забронированная единица закреплена именно за победителем',
);
Race::check($newOrders === 1, 'этим залпом создан ровно один новый заказ', 'новых заказов: '.$newOrders);

$promoAfter = promo_state('GG500');
Race::check(
    ($promoAfter['used_count'] ?? null) === ($promoBefore['used_count'] ?? null),
    'слот постороннего промокода GG500 не израсходован этим залпом (запуск без промокода)',
);

if ($winnerId !== null && $winnerId !== '') {
    Race::step('оплачиваем победителя '.$winnerId);
    request('POST', base_url().'/api/dev/pay/'.$winnerId);

    $final = wait_for_final($winnerId);
    $soldRow = db_value(
        "SELECT count(*) FROM stock_units WHERE offer_id = ? AND state = 'sold' AND reserved_order_id = ?",
        [$hotOfferId, $winnerId],
    );
    $newOrdersAfterPay = (int) db_value('SELECT count(*) FROM orders WHERE offer_id = ?', [$hotOfferId]) - $ordersBefore;

    Race::check(
        ($final['status'] ?? '') === 'delivered',
        'после оплаты победитель выдан',
        'статус: '.($final['status'] ?? '?'),
    );
    Race::check((int) $soldRow === 1, 'единица победителя перешла в состояние sold');
    Race::check(
        $newOrdersAfterPay === 1,
        'ни один проигравший так и не получил заказ на это предложение (2.1, 2.2, 2.3)',
        'новых заказов: '.$newOrdersAfterPay,
    );
}

exit(Race::summary());
