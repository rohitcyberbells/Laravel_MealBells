<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('mealbells:process-cutoff')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('mealbells:generate-recurring-skips')->hourly()->withoutOverlapping()->onOneServer();
