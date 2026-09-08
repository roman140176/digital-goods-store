#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Прогон всех состязательных сценариев с итоговой таблицей.
 *
 * Каждый сценарий независим: он сам приводит склады поставщиков и остатки
 * своих предложений в исходное состояние (восемь сценариев первого этапа —
 * через ensure_offer_available, пять сценариев задачи 14 — через
 * собственные выделенные предложения и dev/admin-ручки), поэтому порядок
 * ниже не имеет значения для корректности — только для читаемости отчёта.
 */

$scenarios = [
    'Двойной клик «Купить»' => 'race-double-click.php',
    '50 вебхуков, один event_id (крит. 1, 2)' => 'race-webhook-same-event.php',
    '50 вебхуков, разные event_id (крит. 1)' => 'race-webhook-distinct-events.php',
    'Вебхуки не по порядку (крит. 3)' => 'race-out-of-order.php',
    'Пустой склад и восстановление (крит. 4)' => 'race-empty-pool.php',
    'Лимит промокодов (крит. 5)' => 'race-promo-limit.php',
    'Ловушка таймаута поставщика' => 'race-timeout-leak.php',
    'Ошибка после выдачи ключа' => 'race-error-after-issue.php',
    'Последняя единица наперегонки (крит. 2.1-2.3)' => 'race-last-unit.php',
    'Оплата против reservations:release (крит. 3.2, 3.3, 6.4)' => 'race-reservation-expiry.php',
    'Захват против release на два места (крит. 3.4)' => 'race-reserve-vs-expire.php',
    'Смена цены в момент оплаты (крит. 1.3)' => 'race-price-change-at-pay.php',
    'SSE: обрыв, реконнект, resync (крит. 1.4)' => 'race-stream-catchup.php',
];

$results = [];

foreach ($scenarios as $title => $file) {
    $code = 0;
    passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/'.$file), $code);
    $results[$title] = $code === 0;
}

$width = 0;
foreach (array_keys($results) as $title) {
    $width = max($width, mb_strlen($title));
}

fwrite(STDOUT, "\n".str_repeat('=', $width + 12)."\n");
fwrite(STDOUT, "  ИТОГ\n");
fwrite(STDOUT, str_repeat('=', $width + 12)."\n");

foreach ($results as $title => $ok) {
    fwrite(STDOUT, sprintf(
        "  %s%s  %s\n",
        $title,
        str_repeat(' ', $width - mb_strlen($title)),
        $ok ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m",
    ));
}

$failed = count(array_filter($results, static fn (bool $ok): bool => ! $ok));
fwrite(STDOUT, str_repeat('-', $width + 12)."\n");
fwrite(STDOUT, sprintf("  Сценариев пройдено: %d из %d\n\n", count($results) - $failed, count($results)));

exit($failed === 0 ? 0 : 1);
