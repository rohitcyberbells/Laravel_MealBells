<?php

namespace App\Http\Controllers\SuperAdmin;

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

        $snapshotsLast24h = MealCount::whereNotNull('locked_at')
            ->where('locked_at', '>=', now()->subHours(24))
            ->count();

        // Detect missing snapshots for today
        $missingSnapshotsToday = [];
        $unconfiguredCompanies = [];
        $companies = Company::with('setting', 'activeAssignment')->get();

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
                $hasLockedCount = MealCount::where('company_id', $company->id)
                    ->where('date', $todayDate)
                    ->whereNotNull('locked_at')
                    ->exists();

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
            'hrms' => $this->hrmsHealth(),
            'hrms_pulls' => $this->hrmsPullHealth(),
            'scheduler' => [
                'last_run_timestamp' => $lastRun,
                'minutes_ago' => $minutesAgo,
                'is_stale' => $isStale,
            ],
            'failed_jobs_count' => $failedJobsCount,
            'snapshots_last_24h' => $snapshotsLast24h,
            'missing_snapshots_today' => $missingSnapshotsToday,
            'unconfigured_companies' => $unconfiguredCompanies,
        ]);
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

        $countsFor = fn ($since) => [
            'failed' => HrmsWebhookEvent::where('status', HrmsWebhookEvent::STATUS_FAILED)
                ->where('created_at', '>=', $since)->count(),
            'blocked' => HrmsWebhookEvent::where('status', HrmsWebhookEvent::STATUS_BLOCKED)
                ->where('created_at', '>=', $since)->count(),
            'stale' => HrmsWebhookEvent::where('status', HrmsWebhookEvent::STATUS_STALE)
                ->where('created_at', '>=', $since)->count(),
        ];

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
