#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__.'/lib.php';

/**
 * Критерий приёмки 1.4: поток рвётся в середине серии изменений, а после
 * реконнекта с Last-Event-ID ни одно событие не теряется.
 *
 * SseTestClient — честный сокет (fsockopen), а не curl: только он даёт
 * прочитать события по одному и оборваться ровно там, где нужно сценарию
 * (см. докблок класса в lib.php). Предложение — своё, ни разу не тронутое
 * ни одним другим сценарием: топик catalog общий на всю витрину (12.1
 * спеки), и чужая активность на нём — это шум, от которого проверка не
 * должна зависеть.
 *
 * Хвост читается ЗАПРОСОМ К БАЗЕ, а не подсчётом кадров: полный список id
 * топика catalog в интересующем диапазоне — источник истины для «ни одного
 * пропуска», а не то, сколько кадров надеялся прочитать клиент.
 */

Race::title('SSE-поток: обрыв в середине серии, реконнект с Last-Event-ID, resync');

$offer = ensure_offer_available('GIFT-ROBLOX-800', 1);
$offerId = (int) $offer['offer_id'];

Race::step('предложение '.$offerId.' (GIFT-ROBLOX-800): базовая цена, затем пара событий на живом клиенте');

// Базовая цена — своя, чтобы вся серия ниже была отличима от того, что уже
// могло быть в offers.price_minor до этого сценария.
$priceSeq = 500000;
admin_post('/admin/offers/'.$offerId.'/price', ['price_minor' => $priceSeq]);

/** Меняет цену предложения и возвращает НОВОЕ значение. */
function bump_price(int $offerId, int &$priceSeq): int
{
    $priceSeq += 1000;
    admin_post('/admin/offers/'.$offerId.'/price', ['price_minor' => $priceSeq]);

    return $priceSeq;
}

/**
 * Читает у клиента событие с КОНКРЕТНОЙ ожидаемой ценой $expectedPrice,
 * отбрасывая всё остальное (чужой топик, чужое предложение, — а в общем
 * прогоне make race-all, где до этого сценария уже отработали 12 других,
 * иногда и лишнее эхо ЕЩЁ не прочитанного клиентом более раннего состояния
 * этого же предложения, живущее в буфере ОС между fsockopen и первым
 * fwrite). Сверка по значению, а не по позиции «что пришло следующим», —
 * позиционная проверка один раз ошиблась именно на этом лишнем кадре и
 * сдвинула весь дальнейший расчёт lastSeenId на единицу.
 */
function read_priced_event(SseTestClient $client, int $offerId, int $expectedPrice, float $timeout = 8.0): ?array
{
    $deadline = microtime(true) + $timeout;

    while (microtime(true) < $deadline) {
        $event = $client->readEvent($timeout);

        if ($event === null) {
            return null;
        }

        $payload = json_decode($event['data'], true);

        if (is_array($payload)
            && (int) ($payload['offer_id'] ?? 0) === $offerId
            && (int) ($payload['price_minor'] ?? -1) === $expectedPrice) {
            return ['id' => $event['id'], 'payload' => $payload];
        }
    }

    return null;
}

$client = new SseTestClient(['catalog']);

$price1 = bump_price($offerId, $priceSeq);
$first = read_priced_event($client, $offerId, $price1);

$price2 = bump_price($offerId, $priceSeq);
$second = read_priced_event($client, $offerId, $price2);

Race::check($first !== null && (int) $first['payload']['price_minor'] === $price1, 'клиент увидел первое из «пары событий»', 'событие: '.json_encode($first));
Race::check($second !== null && (int) $second['payload']['price_minor'] === $price2, 'клиент увидел второе из «пары событий»', 'событие: '.json_encode($second));

$lastSeenId = $second['id'] ?? $first['id'] ?? 0;

Race::step('обрываем соединение резко (fclose), пока клиент не слушает — 5 смен цены');
$client->disconnect();

$hiddenPrices = [];
for ($i = 0; $i < 5; $i++) {
    $hiddenPrices[] = bump_price($offerId, $priceSeq);
}

Race::step('переподключаемся с Last-Event-ID='.$lastSeenId.' и дочитываем хвост');

$reconnected = new SseTestClient(['catalog'], $lastSeenId);

$receivedIds = [];
$receivedPricesInOrder = [];
$lastEvent = null;
$framesRead = 0;

while (count($receivedPricesInOrder) < 5 && $framesRead < 40) {
    $event = $reconnected->readEvent(8.0);
    $framesRead++;

    if ($event === null) {
        break;
    }

    $payload = json_decode($event['data'], true);
    $receivedIds[] = $event['id'];

    if (is_array($payload) && (int) ($payload['offer_id'] ?? 0) === $offerId) {
        $receivedPricesInOrder[] = (int) $payload['price_minor'];
        $lastEvent = ['id' => $event['id'], 'payload' => $payload];
    }
}

Race::check(
    $receivedPricesInOrder === $hiddenPrices,
    'после добора клиент увидел все пять изменений, в правильном порядке (по конечному состоянию и по набору значений)',
    'ожидали: '.json_encode($hiddenPrices).', получили: '.json_encode($receivedPricesInOrder),
);

$currentPriceInDb = (int) db_value('SELECT price_minor FROM offers WHERE id = ?', [$offerId]);
Race::check(
    $lastEvent !== null && (int) $lastEvent['payload']['price_minor'] === $currentPriceInDb,
    'конечное состояние у подписчика совпадает с базой',
    'у клиента: '.($lastEvent['payload']['price_minor'] ?? '?').', в базе: '.$currentPriceInDb,
);

// «Ни одного пропуска в цепочке id топика» — сверка с базой, а не с тем,
// сколько кадров сам клиент насчитал: полный список id topic=catalog в
// диапазоне (курсор реконнекта, последний полученный id] обязан целиком
// лежать внутри набора того, что клиент реально получил (дубли допустимы,
// см. 10.1 спеки, — проверяется отсутствие, а не количество).
$maxReceivedId = $receivedIds === [] ? $lastSeenId : max($receivedIds);
$journalIdsInRange = array_map(
    static fn (array $row): int => (int) $row['id'],
    db_rows('SELECT id FROM stream_events WHERE topic = ? AND id > ? AND id <= ? ORDER BY id', ['catalog', $lastSeenId, $maxReceivedId]),
);
$missingFromClient = array_diff($journalIdsInRange, $receivedIds);

Race::check(
    $missingFromClient === [],
    'ни одного пропуска в непрерывной цепочке id топика catalog между курсором реконнекта и последним полученным id',
    'в журнале, но не у клиента: '.json_encode(array_values($missingFromClient)),
);

// «Повторное применение полученного события не меняет состояние» — берём
// ПОСЛЕДНЕЕ полученное событие (самое свежее состояние) и применяем его
// payload дважды к локальному отражению карточки: он несёт ПОЛНОЕ состояние
// объекта (4.1 спеки), а не дельту, поэтому вторая доставка того же события
// обязана быть неотличима от первой.
$state = [];
$applyOfferSnapshot = static function (array &$state, array $payload): void {
    $state['price_minor'] = $payload['price_minor'];
    $state['available'] = $payload['available'];
    $state['status'] = $payload['status'];
};

$applyOfferSnapshot($state, $lastEvent['payload']);
$stateAfterFirstApply = $state;
$applyOfferSnapshot($state, $lastEvent['payload']);

Race::check(
    $state === $stateAfterFirstApply,
    'повторное применение полученного события не меняет состояние (идемпотентность payload)',
);

// Бонус к 4.6 спеки: гард по версии на объект. Клиент из спеки применяет
// событие, только если его id больше последнего применённого для ЭТОГО
// offer_id, — иначе опоздавшее старое событие (см. 4.3 спеки: событие с
// меньшим id может стать видимым позже события с большим) откатило бы
// актуальную карточку в прошлое. Здесь тот же гард смоделирован явно и
// проверен на первом из «пары» событий — заведомо более старом и по id, и
// по значению цены, чем то, что уже применено выше.
$versioned = ['id' => $lastEvent['id'], 'snapshot' => $lastEvent['payload']];
$applyIfNewer = static function (array &$versioned, int $id, array $payload) {
    if ($id > $versioned['id']) {
        $versioned['id'] = $id;
        $versioned['snapshot'] = $payload;
    }
};

$applyIfNewer($versioned, (int) $first['id'], $first['payload']);

Race::check(
    (int) $versioned['snapshot']['price_minor'] === $currentPriceInDb,
    'гард по версии не даёт устаревшему событию откатить уже применённое более новое состояние',
    'после попытки применить старое событие цена осталась: '.$versioned['snapshot']['price_minor'],
);

$reconnected->disconnect();

// --- Критерий 1.4: resync на курсоре ниже минимального id журнала ---
Race::step('resync: курсор ниже минимального id журнала (имитация ретеншна)');

// PruneStreamEvents чистит журнал раз в пять минут по возрасту строки — за
// секунды одного прогона сценария этого дождаться нельзя. Прямое удаление
// самой старой строки — то же самое допущение, что и dev/reservations/expire
// для брони: не ждать реального времени, а создать точное условие мгновенно
// (отдельной dev-ручки под ретеншн журнала в API нет, поэтому здесь это
// делает единственно возможный способ — прямая запись в базу, см. докблок
// db() в lib.php).
$minIdBefore = (int) db_value('SELECT coalesce(min(id), 0) FROM stream_events');
db_exec('DELETE FROM stream_events WHERE id = ?', [$minIdBefore]);
$minIdAfter = (int) db_value('SELECT coalesce(min(id), 0) FROM stream_events');
$maxIdNow = (int) db_value('SELECT coalesce(max(id), 0) FROM stream_events');

Race::check($minIdAfter > $minIdBefore, 'минимальный id журнала действительно сдвинулся (иначе курсор ниже него — не про ретеншн)', "было {$minIdBefore}, стало {$minIdAfter}");

$resyncClient = new SseTestClient(['catalog'], $minIdBefore);
$resyncEvent = $resyncClient->readEvent(8.0);
$resyncPayload = $resyncEvent !== null ? json_decode($resyncEvent['data'], true) : null;

Race::check(
    $resyncEvent !== null && $resyncEvent['event'] === 'resync',
    'курсор ниже минимального id журнала честно отвечает resync, а не молча урезанным хвостом',
    'получено событие: '.json_encode($resyncEvent),
);
Race::check(
    is_array($resyncPayload) && ($resyncPayload['reason'] ?? '') === 'cursor_out_of_journal',
    'resync называет причину cursor_out_of_journal',
);
Race::check(
    is_array($resyncPayload) && (int) ($resyncPayload['stream_cursor'] ?? -1) === $maxIdNow,
    'resync называет актуальный курсор — максимальный id журнала на момент рукопожатия',
    'в событии: '.($resyncPayload['stream_cursor'] ?? '?').', в базе: '.$maxIdNow,
);

$resyncClient->disconnect();

exit(Race::summary());
