#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__.'/lib.php';

/**
 * Критерий приёмки 3.4: захват и снятие брони одной и той же единицы
 * одновременно.
 *
 * Отличие от race-reservation-expiry.php: там проверяется исход ОДНОГО
 * заказа (оплата против его же release), здесь — что склад из ДВУХ единиц
 * никогда не расходится с числом заказов, которые эти единицы держат, под
 * НАСТОЯЩИМ конкурентным давлением: 10 новых покупателей одновременно с
 * reservations:release, 20 повторов.
 *
 * Подготовка каждого повтора («захватить обе единицы и тут же просрочить
 * бронь») — не часть замеряемой гонки, а предусловие для нее: reserve()
 * подбирает истёкшую бронь лениво (6.1 спеки) только если она вообще есть,
 * поэтому обеим единицам сначала нужно назначить владельца, чтобы было что
 * отбирать. Сама гонка — второй залп: 10 свежих покупателей и release
 * одновременно.
 */

Race::title('10 покупателей против reservations:release на предложении с двумя единицами');

$offer = cheapest_offer('SUB-DISCORD-1M');
$offerId = (int) $offer['offer_id'];

Race::step('предложение '.$offerId.' (SUB-DISCORD-1M), 20 повторов');

/**
 * Возвращает предложение в чистое стартовое состояние — не по памяти
 * процесса, а по факту в базе: находит ВСЕ заказы, которые ПРЯМО СЕЙЧАС
 * держат бронь на это предложение, снимает её и выставляет ровно $units
 * свободных единиц.
 *
 * Именно по базе, а не по списку id, накопленному внутри цикла ниже: список
 * живёт только в памяти ЭТОГО процесса, а сценарий перезапускают вручную
 * (make race-reserve-vs-expire повторно, второй прогон make race-all) — в
 * новом процессе список пуст с самого начала, а предложение вполне может
 * стоять с чужой незакрытой бронью прошлого запуска. Первая версия этой
 * функции принимала список id аргументом и на втором прогоне подряд
 * стабильно копила лишние строки stock_units: /admin/offers/{id}/stock
 * считает только available и достраивает недостающее, не трогая reserved, —
 * забытая бронь пряталась от него безнаказанно.
 */
function reset_offer_between_repeats(int $offerId, int $units): void
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

    admin_post('/admin/offers/'.$offerId.'/stock', ['units' => $units]);

    // Самопроверка физического числа строк, а не доверие к available: тик
    // ФОНОВОГО планировщика (scheduler-контейнер продолжает тикать каждую
    // секунду, эта уборка его не останавливает) мог освободить ЧУЖУЮ, не
    // связанную с этим прогоном просроченную бронь МЕЖДУ подсчётом available
    // выше и его собственным тиком, и /admin/offers/{id}/stock добавил бы
    // лишнюю строку поверх той, что тик вернул мгновение спустя. Проверка
    // сходится к ровно $units и лишние (только available — reserved к этому
    // моменту их уже не осталось) подрезаются напрямую.
    $total = (int) db_value('SELECT count(*) FROM stock_units WHERE offer_id = ?', [$offerId]);

    if ($total > $units) {
        db_exec(
            "DELETE FROM stock_units WHERE id IN (
                SELECT id FROM stock_units WHERE offer_id = ? AND state = 'available' ORDER BY id DESC LIMIT ?
            )",
            [$offerId, $total - $units],
        );
    }
}

$http500 = 0;
$overSoldRepeats = 0;
$sharedUnitRepeats = 0;
$setupFailures = 0;
$capturedTotal = 0;

for ($i = 1; $i <= 20; $i++) {
    reset_offer_between_repeats($offerId, 2);

    // Предусловие: обе единицы уходят двум заказам, бронь тут же
    // просрочивается — теперь release() и reserve() есть за что бороться.
    foreach (['a', 'b'] as $slot) {
        $setup = create_order_for_offer($offerId, null, 'race_rve_setup_'.$i.'_'.$slot.'_'.bin2hex(random_bytes(4)));
        $setupId = (string) ($setup['id'] ?? '');

        if ($setupId === '') {
            $setupFailures++;

            continue;
        }

        request('POST', base_url().'/api/dev/reservations/'.$setupId.'/expire');
    }

    // Сама гонка: 10 свежих покупателей и release стартуют одновременно.
    // volley() кладёт все 10 HTTP-запросов в один curl_multi — они уходят
    // одним залпом, а не по очереди, — а release запускается отдельным
    // процессом чуть раньше, чтобы гарантированно попасть в то же окно.
    $release = artisan_start('reservations:release');

    $requests = [];
    for ($buyer = 1; $buyer <= 10; $buyer++) {
        $requests[] = [
            'method' => 'POST',
            'url' => base_url().'/api/orders',
            'json' => ['offer_id' => $offerId],
            'headers' => ['Idempotency-Key: race_rve_buyer_'.$i.'_'.$buyer.'_'.bin2hex(random_bytes(4))],
        ];
    }

    $results = volley($requests);
    $releaseResult = artisan_finish($release);

    foreach ($results as $result) {
        if ($result['status'] >= 500) {
            $http500++;
        }

        if ($result['status'] === 201 && is_array($result['body'])) {
            $capturedTotal++;
        }
    }

    if ($releaseResult['exit'] !== 0) {
        $http500++;
    }

    // Инвариант проверяется В БАЗЕ, а не по ответам: сколько бы покупателей
    // ни решили, что им досталась единица, физический счёт строк —
    // единственный источник истины.
    $heldCount = (int) db_value(
        "SELECT count(*) FROM stock_units WHERE offer_id = ? AND state IN ('reserved', 'sold')",
        [$offerId],
    );
    $distinctOwners = (int) db_value(
        "SELECT count(DISTINCT reserved_order_id) FROM stock_units
          WHERE offer_id = ? AND reserved_order_id IS NOT NULL AND state IN ('reserved', 'sold')",
        [$offerId],
    );

    if ($heldCount > 2) {
        $overSoldRepeats++;
    }

    if ($distinctOwners > 2) {
        $sharedUnitRepeats++;
    }
}

Race::check($setupFailures === 0, 'предусловие каждого повтора отработало (обе единицы нашли владельца)', 'сбоев подготовки: '.$setupFailures);
Race::check($http500 === 0, 'ни один из 220 конкурентных вызовов (10 покупателей × 20 + release × 20) не завершился 500 или падением процесса', 'проблемных: '.$http500);
Race::check(
    $overSoldRepeats === 0,
    'сумма reserved + sold никогда не превышает числа единиц предложения (2) ни в одном из 20 повторов',
    'повторов с перепроданностью: '.$overSoldRepeats,
);
Race::check(
    $sharedUnitRepeats === 0,
    'ни одна единица не оказалась одновременно за двумя заказами (count(distinct reserved_order_id) <= 2)',
    'повторов с расхождением: '.$sharedUnitRepeats,
);
Race::check(
    $capturedTotal > 0,
    'за 20 повторов свежие покупатели реально перехватывали освобождающиеся единицы (гонка не вырождена)',
    'успешных захватов среди 200 попыток: '.$capturedTotal,
);

exit(Race::summary());
