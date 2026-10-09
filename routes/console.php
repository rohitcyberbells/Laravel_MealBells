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
//
// The flags are passed as bare values, not as ['--all' => true]: that renders
// as --all='1' and Symfony refuses a value on a boolean option, so every run
// failed before the command started.
Schedule::command('hrms:pull', ['--all'])
    ->cron('*/'.max(1, (int) config('hrms.cyberpulse.pull_every_minutes', 15)).' * * * *')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('hrms:pull', ['--all', '--before-cutoff'])
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Attendance, shortly before each company's cutoff and only for companies that
// have switched it on. Shadow mode: it records what it saw and reports what it
// would have changed, and changes no count.
//
// After the leave pull on purpose - leave should already be applied, so anyone
// still unaccounted for is genuinely unaccounted for. Every minute, acting once
// per window, so a company whose cutoff is 11:00 is read promptly rather than
// five times.
Schedule::command('hrms:pull-attendance', ['--all', '--before-cutoff'])
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();
Schedule::command('model:prune', ['--model' => [HrmsWebhookEvent::class, HrmsPullRun::class]])
    ->dailyAt('03:00')
    ->onOneServer();

// The framework's own tables only grow: sessions, the notification feed and
// failed jobs. They hold no meal records, but they carry user ids and job
// payloads, and nothing was clearing them.
Schedule::command('mealbells:prune-operational-data')
    ->dailyAt('03:15')
    ->onOneServer();

// Nothing used to alert: the health page had to be opened by a person, and the
// failures that cost the most - the scheduler dead, the worker stopped, a pull
// that silently stopped - all look exactly like a quiet day with no leave.
//
// Every five minutes, with the same issue mailed at most once an hour.
Schedule::command('mealbells:health-alerts')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Before the prune, so a snapshot exists of whatever the prune is about to
// remove. A failure is recorded and shows on the health page.
Schedule::command('mealbells:backup')
    ->dailyAt('02:30')
    ->onOneServer();
