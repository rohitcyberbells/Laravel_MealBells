<?php

use App\Models\HrmsPullRun;
use App\Models\HrmsWebhookEvent;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('mealbells:process-cutoff')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('mealbells:generate-recurring-skips')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('hrms:reconcile')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// An HRMS with no webhooks has to be polled. The regular cadence keeps the day
// roughly current; the second entry runs every minute but only acts inside each
// company's pre-cutoff window, so leave approved during the morning still
// reaches the count before it locks.
Schedule::command('hrms:pull', ['--all' => true])
    ->cron('*/'.max(1, (int) config('hrms.cyberpulse.pull_every_minutes', 15)).' * * * *')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('hrms:pull', ['--all' => true, '--before-cutoff' => true])
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();
Schedule::command('model:prune', ['--model' => [HrmsWebhookEvent::class, HrmsPullRun::class]])
    ->dailyAt('03:00')
    ->onOneServer();
