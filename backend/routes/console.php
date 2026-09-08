<?php

use App\Console\Commands\PruneStreamEvents;
use App\Console\Commands\ReconcileDeliveries;
use App\Console\Commands\ReleaseExpiredReservations;
use Illuminate\Support\Facades\Schedule;

// Подбирает выдачи, зависшие из-за таймаутов и падений поставщиков.
Schedule::command(ReconcileDeliveries::class)->everyMinute()->withoutOverlapping();

// Снимает просроченные брони и публикует их всем сразу (3.2 ТЗ). Тик раз в
// секунду, а не раз в минуту, — TTL брони считается в секундах
// (RESERVATION_TTL_SECONDS), и минутный шаг оставлял бы товар «залипшим» до
// минуты сверх дедлайна. everySecond() — sub-minute scheduling Laravel 11+,
// его подхватывает только планировщик-цикл (`schedule:work`), а не разовый
// cron-вызов `schedule:run` раз в минуту.
//
// withoutOverlapping(2): аргумент обязателен, а не по умолчанию. Без него
// лок живёт 1440 минут (сутки) — для минутного ReconcileDeliveries это
// почти не важно, а для секундного тика фатально: один по-настоящему
// зависший прогон (обрыв соединения с базой, зависшая блокировка строки) на
// сутки остановил бы снятие броней вообще, то есть ровно то, от чего
// планировщик должен защищать. 2 минуты — запас на порядки больше нормы:
// живой тик занимает 100–150 мс (см. отчёт задачи 5).
Schedule::command(ReleaseExpiredReservations::class)->everySecond()->withoutOverlapping(2);

// Ретеншн журнала реалтайм-событий — час (см. PruneStreamEvents), чистка раз
// в пять минут: журнал легковесный, чаще незачем, а реже — раздувает таблицу.
Schedule::command(PruneStreamEvents::class)->everyFiveMinutes()->withoutOverlapping();
