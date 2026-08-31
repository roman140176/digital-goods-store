<?php

use App\Console\Commands\ReconcileDeliveries;
use Illuminate\Support\Facades\Schedule;

// Подбирает выдачи, зависшие из-за таймаутов и падений поставщиков.
Schedule::command(ReconcileDeliveries::class)->everyMinute()->withoutOverlapping();
