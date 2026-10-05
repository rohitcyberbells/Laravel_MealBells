<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
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
        $companies = Company::with('setting', 'activeAssignment')->get();

        foreach ($companies as $company) {
            if (! $company->activeAssignment) {
                continue;
            }

            $timezone = $company->setting?->timezone ?? 'Asia/Kolkata';
            $todayDate = Carbon::today($timezone)->toDateString();

            if (! MealCalendar::isMealDay($company, $todayDate)) {
                continue;
            }

            $cutoffStr = $company->setting?->cutoff_time ?? '11:00';
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
            'scheduler' => [
                'last_run_timestamp' => $lastRun,
                'minutes_ago' => $minutesAgo,
                'is_stale' => $isStale,
            ],
            'failed_jobs_count' => $failedJobsCount,
            'snapshots_last_24h' => $snapshotsLast24h,
            'missing_snapshots_today' => $missingSnapshotsToday,
        ]);
    }
}
