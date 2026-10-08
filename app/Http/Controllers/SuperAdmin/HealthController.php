<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Console\Commands\BackupDatabase;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyHrmsConnection;
use App\Models\HrmsWebhookEvent;
use App\Models\MealCount;
use App\Services\MealCalendar;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class HealthController extends Controller
{
    public function index()
    {
        $lastRun = Cache::get('scheduler_last_run');
        $now = now();

        $minutesAgo = null;
        $isStale = true;

        if ($lastRun) {
            $lastRunCarbon = Carbon::createFromTimestamp($lastRun);
            $minutesAgo = (int) $lastRunCarbon->diffInMinutes($now);
            $isStale = $minutesAgo > 3;
        }

        $failedJobsCount = DB::table('failed_jobs')->count();

        // A count alone is not actionable. The oldest one says whether this is
        // a burst from five minutes ago or something that has been sitting for
        // a week, and the queue names say which part of the system is stuck.
        $failedJobs = [
            'count' => $failedJobsCount,
            'oldest_at' => DB::table('failed_jobs')->min('failed_at'),
            'newest_at' => DB::table('failed_jobs')->max('failed_at'),
            'by_queue' => DB::table('failed_jobs')
                ->selectRaw('queue, count(*) as total')
                ->groupBy('queue')
                ->orderByDesc('total')
                ->limit(5)
                ->get(),
            // Printed rather than offered as a button: retrying blindly can
            // re-apply work that a human should look at first.
            'retry_command' => 'php artisan queue:retry all',
        ];

        $snapshotsLast24h = MealCount::whereNotNull('locked_at')
            ->where('locked_at', '>=', now()->subHours(24))
            ->count();

        // Detect missing snapshots for today
        $missingSnapshotsToday = [];
        $unconfiguredCompanies = [];
        $companies = Company::with('setting', 'activeAssignment')->get();

        // One pass for every company's locked snapshot, instead of an exists()
        // inside the loop.
        $lockedToday = MealCount::whereNotNull('locked_at')
            ->whereIn('company_id', $companies->pluck('id'))
            ->whereBetween('date', [
                now()->subDay()->toDateString(),
                now()->addDay()->toDateString(),
            ])
            ->get(['company_id', 'date'])
            ->map(fn ($row) => $row->company_id.':'.Carbon::parse($row->date)->toDateString())
            ->all();

        foreach ($companies as $company) {
            if (! $company->activeAssignment) {
                continue;
            }

            // ProcessDailyCutoff skips a company with no settings row outright,
            // so it will never produce a snapshot. Reporting that as a missing
            // snapshot was a false alarm that could never clear - the real
            // problem is the missing configuration, so it is surfaced as that.
            if (! $company->setting) {
                $unconfiguredCompanies[] = [
                    'company_id' => $company->id,
                    'company_name' => $company->name,
                ];

                continue;
            }

            $timezone = $company->setting->timezone ?? 'Asia/Kolkata';
            $todayDate = Carbon::today($timezone)->toDateString();

            if (! MealCalendar::isMealDay($company, $todayDate)) {
                continue;
            }

            $cutoffStr = $company->setting->cutoff_time ?? '11:00';
            $parts = explode(':', $cutoffStr);
            $cutoffDateTime = Carbon::createFromFormat('Y-m-d', $todayDate, $timezone)
                ->setTime((int) ($parts[0] ?? 11), (int) ($parts[1] ?? 0), 0);

            $nowInCompanyTz = Carbon::now($timezone);

            if ($nowInCompanyTz->gte($cutoffDateTime)) {
                $hasLockedCount = in_array($company->id.':'.$todayDate, $lockedToday, true);

                if (! $hasLockedCount) {
                    $missingSnapshotsToday[] = [
                        'company_id' => $company->id,
                        'company_name' => $company->name,
                        'date' => $todayDate,
                        'cutoff_time' => $cutoffStr,
                    ];
                }
            }
        }

        return Inertia::render('SuperAdmin/Health', [
            'backup' => $this->backupHealth(),
            'hrms' => $this->hrmsHealth(),
            'hrms_pulls' => $this->hrmsPullHealth(),
            'scheduler' => [
                'last_run_timestamp' => $lastRun,
                'minutes_ago' => $minutesAgo,
                'is_stale' => $isStale,
            ],
            'failed_jobs_count' => $failedJobsCount,
            'failed_jobs' => $failedJobs,
            'snapshots_last_24h' => $snapshotsLast24h,
            'missing_snapshots_today' => $missingSnapshotsToday,
            'unconfigured_companies' => $unconfiguredCompanies,
        ]);
    }

    /**
     * The last backup, and whether it is recent enough to be worth anything.
     *
     * A backup that silently stopped is only discovered when someone needs it,
     * which is the worst possible moment - so "never run" and "ran but failed"
     * are both reported here as problems rather than as absence.
     *
     * @return array<string, mixed>
     */
    protected function backupHealth(): array
    {
        $status = Cache::get(BackupDatabase::STATUS_KEY);
        $staleAfterHours = (int) config('backup.stale_after_hours', 36);

        if (! $status) {
            return [
                'ever_run' => false,
                'ok' => false,
                'is_stale' => true,
                'at' => null,
                'hours_ago' => null,
                'stale_after_hours' => $staleAfterHours,
                'error' => 'No backup has ever been recorded.',
                'path' => null,
                'size_kb' => null,
            ];
        }

        $at = Carbon::parse($status['at']);
        $hoursAgo = (int) $at->diffInHours(now());

        return [
            'ever_run' => true,
            'ok' => (bool) $status['ok'],
            'is_stale' => $hoursAgo > $staleAfterHours,
            'at' => $at->toDateTimeString(),
            'hours_ago' => $hoursAgo,
            'stale_after_hours' => $staleAfterHours,
            'error' => $status['error'] ?? null,
            'path' => $status['path'] ?? null,
            'size_kb' => isset($status['bytes']) ? (int) round($status['bytes'] / 1024) : null,
        ];
    }

    /**
     * One row per company we poll rather than receive from.
     *
     * A pull has no per-event delivery to look at, so its last run is the only
     * evidence it is working. 'is_stale' is the signal that matters: a pull that
     * silently stopped looks exactly like a quiet day of no leave, and nobody
     * would notice until someone's meal was counted while they were away.
     *
     * Credentials are never read here - only whether they exist.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function hrmsPullHealth(): array
    {
        $staleAfter = (int) config('hrms.cyberpulse.pull_every_minutes', 15) * 3;

        return CompanyHrmsConnection::with('company:id,name,code')
            ->whereNotNull('pull_base_url')
            ->get()
            ->map(function (CompanyHrmsConnection $connection) use ($staleAfter) {
                $summary = $connection->last_pull_summary ?? [];
                $minutesAgo = $connection->last_pull_at
                    ? (int) $connection->last_pull_at->diffInMinutes(now())
                    : null;

                return [
                    'company_id' => $connection->company_id,
                    'company_name' => $connection->company?->name,
                    'company_code' => $connection->company?->code,
                    'adapter' => $connection->pull_adapter,
                    'last_pull_at' => $connection->last_pull_at?->toDateTimeString(),
                    'minutes_ago' => $minutesAgo,
                    'is_stale' => $minutesAgo === null || $minutesAgo > $staleAfter,
                    'stale_after_minutes' => $staleAfter,
                    'status' => $connection->last_pull_status,
                    'error' => $connection->last_pull_error,
                    // Surfaced individually because each one is a different kind
                    // of problem: unknown employees are a mapping gap, warnings
                    // mean a safety guard stopped the run acting.
                    'applied' => $summary['applied'] ?? null,
                    'cancelled' => $summary['cancelled'] ?? null,
                    'ignored' => $summary['ignored'] ?? null,
                    'unknown_employee' => $summary['unknown_employee'] ?? null,
                    'warnings' => $summary['warnings'] ?? [],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * HRMS webhook health.
     *
     * 'stuck' counts events accepted but never processed past the reconcile
     * window - the signal that the queue worker is not running, which is the one
     * failure mode that silently loses leave.
     *
     * 'abandoned' counts failed events the backstop has given up on, so they do
     * not sit invisible behind a retry counter.
     *
     * @return array<string, mixed>
     */
    protected function hrmsHealth(): array
    {
        $staleMinutes = (int) config('hrms.reconcile.stale_after_minutes', 10);
        $maxAttempts = (int) config('hrms.reconcile.max_attempts', 3);

        $startOfToday = now()->startOfDay();
        $sevenDaysAgo = now()->subDays(7);

        // One grouped query per window instead of one per status: this was six
        // counts for two windows of three statuses.
        $countsFor = function ($since) {
            $tallies = HrmsWebhookEvent::selectRaw('status, count(*) as total')
                ->whereIn('status', [
                    HrmsWebhookEvent::STATUS_FAILED,
                    HrmsWebhookEvent::STATUS_BLOCKED,
                    HrmsWebhookEvent::STATUS_STALE,
                ])
                ->where('created_at', '>=', $since)
                ->groupBy('status')
                ->pluck('total', 'status');

            return [
                'failed' => (int) $tallies->get(HrmsWebhookEvent::STATUS_FAILED, 0),
                'blocked' => (int) $tallies->get(HrmsWebhookEvent::STATUS_BLOCKED, 0),
                'stale' => (int) $tallies->get(HrmsWebhookEvent::STATUS_STALE, 0),
            ];
        };

        $lastEvent = HrmsWebhookEvent::latest('created_at')->first();

        $stuck = HrmsWebhookEvent::with('company:id,name,code')
            ->where('status', HrmsWebhookEvent::STATUS_RECEIVED)
            ->where('created_at', '<=', now()->subMinutes($staleMinutes))
            ->orderBy('created_at')
            ->limit(20)
            ->get()
            ->map(fn (HrmsWebhookEvent $event) => [
                'id' => $event->id,
                'external_event_id' => $event->external_event_id,
                'company_name' => $event->company?->name,
                'event_type' => $event->event_type,
                'created_at' => $event->created_at?->toDateTimeString(),
                'minutes_waiting' => (int) $event->created_at?->diffInMinutes(now()),
            ]);

        return [
            'today' => $countsFor($startOfToday),
            'last_7_days' => $countsFor($sevenDaysAgo),
            'total_events' => HrmsWebhookEvent::count(),
            'last_event_at' => $lastEvent?->created_at?->toDateTimeString(),
            'stale_after_minutes' => $staleMinutes,
            'stuck_count' => $stuck->count(),
            'stuck_events' => $stuck,
            'abandoned_count' => HrmsWebhookEvent::where('status', HrmsWebhookEvent::STATUS_FAILED)
                ->where('reconcile_attempts', '>=', $maxAttempts)
                ->count(),
        ];
    }
}
