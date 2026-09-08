#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__.'/lib.php';

/**
 * Критерии 3.2, 3.3 и ветки 6.4 спеки: оплата приходит в момент истечения
 * брони.
 *
 * «Одновременно» здесь — не фигура речи: сценарий сам запускает
 * reservations:release отдельным процессом (artisan_start) РЯДОМ с обычным
 * HTTP-запросом на оплату, а не полагается на секундный тик
 * scheduler-контейнера. Тик планировщика и без сценария продолжает идти сам
 * по себе (он не отключается), но полагаться только на него означало бы не
 * управлять моментом столкновения: за короткое окно теста оплата почти
 * всегда успевала бы раньше, чем планировщик вообще проснётся, и вторая
 * ветка (заказ остался без единицы) ни разу не случилась бы — сценарий
 * иллюстрировал бы, а не доказывал.
 *
 * Каждый третий повтор добавляет НЕЗАВИСИМОГО соперника: единица одна на
 * предложение, и если её некому перехватить, оплата всегда либо удержит,
 * либо перезахватит СВОЮ ЖЕ бронь — вторая ветка 6.4 спеки (единицу забрал
 * кто-то другой, заказ уходит в out_of_stock) без соперника ни разу не
 * случилась бы за все 30 повторов, и сценарий доказывал бы только половину
 * дерева исходов.
 */

Race::title('Оплата против reservations:release: 30 повторов на предложении с одной единицей');

// Склад поставщиков — с запасом под все возможные оплаты этого прогона (до
// 31 успешной доставки: 30 повторов + детерминированный случай пункта 4).
// Без этого «out_of_stock» из-за исчерпания ключей у поставщика (посторонняя
// причина, см. IssueOrderCode::giveUp — она НЕ выставляет refund_required)
// неотличимо в ответе API от «out_of_stock» из-за отсутствия единицы (см.
// ApplyPaymentEvent::applyPaidWithoutStock — выставляет refund_required)
// — статус тот же самый, а сценарий проверяет именно вторую причину.
suppliers_ready(80);

$offer = cheapest_offer('SUB-SPOTIFY-1M');
$offerId = (int) $offer['offer_id'];

Race::step('предложение '.$offerId.' (SUB-SPOTIFY-1M), 30 повторов «заказ → бронь просрочена → оплата и release одновременно»');

/**
 * Возвращает единицу предложения в чистое стартовое состояние — не по
 * памяти процесса, а по факту в базе: находит ВСЕ заказы, которые ПРЯМО
 * СЕЙЧАС держат бронь на это предложение, снимает её и выставляет ровно
 * одну свободную единицу.
 *
 * Поиск идёт запросом к stock_units, а не по списку id, накопленному в
 * цикле ниже: список живёт только в памяти ЭТОГО процесса, а сценарий
 * перезапускают вручную (make race-reservation-expiry повторно, второй
 * прогон make race-all) — в новом процессе список пуст, а соперник
 * прошлого запуска (см. ниже) вполне может стоять с чужой незакрытой
 * бронью. /admin/offers/{id}/stock считает только available и достраивает
 * недостающее, не трогая reserved, — забытая бронь пряталась бы от него
 * безнаказанно и копилась строка за строкой на каждом перезапуске.
 */
function reset_single_unit_offer(int $offerId): void
{
    $stillReserved = db_rows(
        "SELECT DISTINCT reserved_order_id FROM stock_units WHERE offer_id = ? AND state = 'reserved'",
        [$offerId],
    );

    foreach ($stillReserved as $row) {
        request('POST', base_url().'/api/dev/reservations/'.$row['reserved_order_id'].'/expire');
    }

    if ($stillReserved !== []) {
        artisan_run('reservations:release');
    }

    admin_post('/admin/offers/'.$offerId.'/stock', ['units' => 1]);
}

/**
 * Оплата с повтором на транзиентный сбой БД, а не на бизнес-исход.
 *
 * Под настоящей конкуренцией с reservations:release (не только в этом
 * сценарии — так же ведёт себя вебхук платёжки в бою) UPDATE stock_units
 * из sellForOrder() и UPDATE того же stock_units из releaseExpired() иногда
 * ловят цикл ожидания друг на друге, и PostgreSQL разрешает его, откатывая
 * ОДНУ из двух транзакций кодом 40P01 (deadlock detected) — это штатная
 * защита движка от зависания, а не повреждение данных. Контракт вебхука
 * прямо объявляет: «код 5xx — платёжная система повторяет доставку» (см.
 * докблок WebhookController::handle и PaymentSimulatorController) — здесь
 * сценарий имитирует ровно это поведение настоящего провайдера. Retry шлёт
 * НОВЫЙ номер попытки: event_id детерминирован из attempt (5.3 спеки), и
 * без нового номера повтор просто наткнулся бы на уже вставленную (и уже
 * закончившуюся ошибкой обработки) строку и ничего не сделал бы заново.
 */
function pay_with_retries(string $orderId, int $maxAttempts = 5): array
{
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $result = request('POST', base_url().'/api/dev/pay/'.$orderId.'?attempt='.$attempt);

        // /api/dev/pay сам отвечает 200 почти всегда — это только
        // подтверждение, что эмулятор ПОСЛАЛ вебхук. Судьба самого вебхука
        // (в том числе дедлок из абзаца выше) лежит ВНУТРИ тела, полем
        // webhook_status: внешний код ответа не годится критерием повтора,
        // им нужно поле изнутри.
        $webhookStatus = (int) ($result['body']['webhook_status'] ?? $result['status']);

        if ($webhookStatus < 500) {
            return $result;
        }
    }

    return $result;
}

$outcomes = ['paid' => 0, 'out_of_stock' => 0, 'other' => 0];
$http500 = 0;
$doubleSaleViolations = 0;
$missingRefundFlag = 0;
$rivalDidNotCapture = 0;
$setupFailures = 0;

for ($i = 1; $i <= 30; $i++) {
    reset_single_unit_offer($offerId);

    $order = create_order_for_offer($offerId, null, 'race_expiry_main_'.$i.'_'.bin2hex(random_bytes(6)));
    $orderId = (string) ($order['id'] ?? '');

    if ($orderId === '') {
        // reset_single_unit_offer() только что подтвердил единицу свободной,
        // поэтому отказ здесь — не «раскупили», а либо чужой процесс успел
        // раньше (ambient-тик scheduler-контейнера тоже дёргает эту же
        // единицу — он не отключается на время сценария), либо мгновенный
        // сбой этого же класса, что и retry оплаты ниже. Повтор ничего не
        // проверил бы честно — пропускаем его, а не подставляем пустой id
        // в дальнейшие вызовы.
        $setupFailures++;

        continue;
    }

    request('POST', base_url().'/api/dev/reservations/'.$orderId.'/expire');

    // Каждый третий повтор — с соперником: покупатель, который лазейкой
    // reserve() (истёкшая бронь подбирается прямо в запросе, см. 6.1 спеки)
    // перехватывает единицу СРАЗУ после expire, ещё до залпа. Без соперника
    // единице некуда деться, кроме как обратно тому же заказу, — оплата
    // всегда выигрывала бы, и вторая ветка требования 3.2/6.4 (единицу
    // забрали раньше) ни разу бы не случилась на 30 повторах: сценарий
    // проверял бы только половину дерева исходов.
    $withRival = $i % 3 === 0;

    if ($withRival) {
        $rivalResponse = request('POST', base_url().'/api/orders', ['offer_id' => $offerId], [
            'Idempotency-Key: race_expiry_rival_'.$i.'_'.bin2hex(random_bytes(6)),
        ]);

        if ($rivalResponse['status'] !== 201) {
            // Соперник не смог перехватить только что просроченную бронь —
            // повтор всё равно валиден (это НЕ ошибка), но перестаёт быть
            // «повтором с соперником»: считаем отдельно, чтобы видеть,
            // насколько надёжно срабатывает эта половина сценария.
            $rivalDidNotCapture++;
        }
    }

    // Настоящая гонка: подпроцесс release стартует и сразу отпускается, а
    // HTTP-запрос оплаты уходит следом, не дожидаясь его завершения — оба
    // одновременно борются за одну и ту же строку stock_units. С соперником
    // выше единицы для release уже нет (борьба вырождена и заведомо
    // закончится out_of_stock), без соперника — оплата обязана удержать или
    // перезахватить её сама.
    $release = artisan_start('reservations:release');
    $payResult = pay_with_retries($orderId);
    $releaseResult = artisan_finish($release);

    $webhookStatus = (int) ($payResult['body']['webhook_status'] ?? $payResult['status']);

    if ($webhookStatus >= 500 || $releaseResult['exit'] !== 0) {
        $http500++;
    }

    $final = get_order($orderId);
    $status = (string) ($final['status'] ?? '');

    if (in_array($status, ['paid', 'delivering', 'delivered'], true)) {
        $outcomes['paid']++;

        $soldForThisOrder = (int) db_value(
            "SELECT count(*) FROM stock_units WHERE offer_id = ? AND state = 'sold' AND reserved_order_id = ?",
            [$offerId, $orderId],
        );

        if ($soldForThisOrder !== 1) {
            $doubleSaleViolations++;
        }
    } elseif ($status === 'out_of_stock') {
        $outcomes['out_of_stock']++;

        if (($final['refund_required'] ?? false) !== true) {
            $missingRefundFlag++;
        }
    } else {
        $outcomes['other']++;
    }
}

Race::check(
    $setupFailures === 0,
    'подготовка каждого из 30 повторов (создание основного заказа на свежую единицу) прошла успешно',
    'сбоев подготовки: '.$setupFailures,
);
Race::check(
    $http500 === 0,
    'после повторов оплата (до 5 попыток на транзиентный сбой БД) ни разу не осталась на 5xx, ни один release не упал',
    'проблемных: '.$http500,
);
Race::check(
    $rivalDidNotCapture === 0,
    'каждый из 10 соперников успел перехватить просроченную бронь (иначе повтор ничего не проверяет)',
    'не перехватили: '.$rivalDidNotCapture.' из 10',
);
Race::check(
    $outcomes['other'] === 0,
    'исход каждого повтора — один из двух допустимых (paid или out_of_stock)',
    'прочих исходов: '.$outcomes['other'].' ('.json_encode($outcomes, JSON_UNESCAPED_UNICODE).')',
);
Race::check($doubleSaleViolations === 0, 'ни разу оплаченный заказ не остался без ровно одной проданной единицы (2.3, 3.3)', 'нарушений: '.$doubleSaleViolations);
Race::check($missingRefundFlag === 0, 'каждый out_of_stock исход помечен refund_required = true', 'без пометки: '.$missingRefundFlag);

// «Никогда: две брони на одну единицу» — не отдельный SQL-запрос, а то же
// самое утверждение, что уже проверено 30 раз выше по каждому повтору:
// частичный UNIQUE stock_units_one_order_per_unit физически не даёт двум
// заказам одновременно держать reserved_order_id одной строки, а нулевые
// http500 выше доказывают, что это ограничение никогда не всплывало наружу
// необработанной ошибкой 500.
Race::step(sprintf(
    'распределение исходов за 30 повторов: paid=%d, out_of_stock=%d',
    $outcomes['paid'],
    $outcomes['out_of_stock'],
));
Race::check(
    $outcomes['paid'] > 0,
    'ветка «оплата успела удержать или перезахватить единицу» реально наблюдалась',
);
Race::check(
    $outcomes['out_of_stock'] > 0,
    'ветка «бронь снята и единицу забрал другой покупатель раньше» реально наблюдалась — не только иллюстрация',
    'если это ноль — гонка вырождена и залп нужно перебалансировать по времени',
);

// --- Критерий 4: поздняя оплата ПОСЛЕ снятия брони перезахватывает единицу ---
// Детерминированный, не состязательный случай: release прогоняется синхронно
// и заведомо раньше оплаты, чтобы наверняка показать саму ветку 6.4
// (SoldReclaimed), а не полагаться на то, что она хоть раз выпадет в цикле
// выше.
Race::step('пункт 4: release гарантированно раньше оплаты — перезахват и выдача');

reset_single_unit_offer($offerId);

$lateOrder = create_order_for_offer($offerId, null, 'race_expiry_late_'.bin2hex(random_bytes(8)));

request('POST', base_url().'/api/dev/reservations/'.$lateOrder['id'].'/expire');
artisan_run('reservations:release');

$expired = get_order($lateOrder['id']);
Race::check(
    ($expired['status'] ?? '') === 'reservation_expired',
    'бронь снята раньше оплаты, заказ ушёл в reservation_expired',
    'статус: '.($expired['status'] ?? '?'),
);

pay_with_retries((string) $lateOrder['id']);
$paidLate = get_order($lateOrder['id']);

$reclaimAudit = array_values(array_filter(
    $paidLate['history'] ?? [],
    static fn (array $row): bool => $row['action'] === 'payment_paid',
));

Race::check(
    in_array($paidLate['status'] ?? '', ['paid', 'delivering', 'delivered'], true),
    'заказ с уже снятой бронью после оплаты уходит в выдачу (склад пополнен release-ом)',
    'статус: '.($paidLate['status'] ?? '?'),
);
Race::check(
    $reclaimAudit !== [] && $reclaimAudit[0]['from'] === 'reservation_expired',
    'по истории заказа видно, что это именно перезахват (переход paid случился ИЗ reservation_expired, а не из created)',
);

// --- Гарантия releaseExpired(): условие o.status = 'created' ---
//
// Заказы 1..30 выше никогда не показывают, зачем нужно именно это условие:
// releaseExpired() трогает только state='reserved', а оплаченная единица
// уже 'sold' (сама транзакция оплаты меняет оба поля атомарно) — снаружи
// никогда не видно «paid снаружи, reserved внутри» просто по построению
// sellForOrder(). Реальная опасность в другом заказе: applyFailed()
// переводит заказ в payment_failed, но НЕ трогает его единицу вовсе — она
// остаётся 'reserved' с прежним дедлайном. 6.4 спеки прямо разрешает
// вернуть такой заказ в paid поздним подтверждением (payment_failed — один
// из acceptsPayment()), и до этого момента его единица обязана
// принадлежать ЕМУ. Без условия o.status='created' releaseExpired() тихо
// отбирает эту единицу, как только реальное время догонит reserved_until.
//
// Проверка идёт ПРЯМО ПО БАЗЕ, а не через попытку постороннего покупателя:
// reserve() сам умеет лениво подбирать «просроченную» бронь (см. 6.1 спеки)
// СОВЕРШЕННО НЕЗАВИСИМО от releaseExpired() и без всякой оглядки на статус
// заказа-держателя — это отдельный код с отдельным запросом. Соперник
// увидел бы единицу свободной в ОБОИХ случаях (guard есть или нет), просто
// разными путями, и не отличил бы их друг от друга. Единственный сигнал,
// который меняется РОВНО из-за guard'а в releaseExpired(), — состояние
// самой строки stock_units сразу после тика планировщика.
Race::step('гарантия releaseExpired(): payment_failed не отдаёт единицу планировщику');

reset_single_unit_offer($offerId);

$failedOrder = create_order_for_offer($offerId, null, 'race_expiry_failed_'.bin2hex(random_bytes(8)));

$failResult = request('POST', base_url().'/api/webhook/payment', webhook_request(
    (string) $failedOrder['id'],
    (int) $failedOrder['total_minor'],
    'evt_expiry_fail_'.bin2hex(random_bytes(6)),
    'failed',
)['json']);

$afterFail = get_order($failedOrder['id']);
Race::check(
    is_array($failResult['body']) && ($failResult['body']['outcome'] ?? '') === 'applied'
        && ($afterFail['status'] ?? '') === 'payment_failed',
    'отказ применён, заказ в payment_failed',
    'статус: '.($afterFail['status'] ?? '?'),
);

// Бронь просрочена НАРОЧНО, хотя заказ уже не created — именно эта
// комбинация (payment_failed + просроченный дедлайн) и есть то состояние,
// которое условие o.status='created' обязано отличить от «единица
// свободна для планировщика».
request('POST', base_url().'/api/dev/reservations/'.$failedOrder['id'].'/expire');
artisan_run('reservations:release');

$unitAfterTick = db_rows(
    "SELECT state, reserved_order_id FROM stock_units WHERE reserved_order_id = ?",
    [$failedOrder['id']],
);

Race::check(
    $unitAfterTick !== [] && $unitAfterTick[0]['state'] === 'reserved',
    'единица осталась в состоянии reserved за payment_failed-заказом после тика планировщика (guard o.status=created сработал)',
    'строка: '.json_encode($unitAfterTick),
);

// И закрывает картину: позднее подтверждение по ТОЙ ЖЕ единице всё ещё
// побеждает — заказ не потерян, потому что единица никуда не делась.
pay_with_retries((string) $failedOrder['id']);
$resurrected = get_order($failedOrder['id']);

Race::check(
    in_array($resurrected['status'] ?? '', ['paid', 'delivering', 'delivered'], true),
    'позднее подтверждение по payment_failed-заказу всё ещё удерживает СВОЮ единицу и уходит в выдачу',
    'статус: '.($resurrected['status'] ?? '?'),
);

exit(Race::summary());
