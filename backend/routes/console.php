<?php

use App\Console\Commands\PruneStreamEvents;
use App\Console\Commands\ReconcileDeliveries;
use Illuminate\Support\Facades\Schedule;

// Подбирает выдачи, зависшие из-за таймаутов и падений поставщиков.
Schedule::command(ReconcileDeliveries::class)->everyMinute()->withoutOverlapping();

// Ретеншн журнала реалтайм-событий — час (см. PruneStreamEvents), чистка раз
// в пять минут: журнал легковесный, чаще незачем, а реже — раздувает таблицу.
Schedule::command(PruneStreamEvents::class)->everyFiveMinutes()->withoutOverlapping();
