<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyCalendarDay;
use Carbon\Carbon;

/**
 * Per-request memo of calendar overrides.
 *
 * isMealDay() is asked the same question many times in one request - the
 * employee dashboard alone asked it sixteen times for eight days - and each ask
 * was its own query. The answers cannot change mid-request unless this request
 * is the one changing them, which is what forget() is for.
 *
 * Registered as a singleton, so the container disposes of it between requests
 * and between tests. Static state would leak across both.
 */
class MealCalendarCache
{
    /** @var array<int, array<string, ?CompanyCalendarDay>> */
    protected array $overrides = [];

    /** @var array<int, bool> whole-range loads already done, by company */
    protected array $loadedRanges = [];

    /**
     * Fetch every override in a range in one query.
     *
     * Called by the pages that walk a span of days. Anything not asked for here
     * still resolves on its own, one query at a time.
     */
    public function preload(Company $company, string $from, string $to): void
    {
        $key = $company->id.':'.$from.':'.$to;

        if (isset($this->loadedRanges[$key])) {
            return;
        }

        $days = CompanyCalendarDay::where('company_id', $company->id)
            ->whereBetween('date', [$from, $to])
            ->get()
            ->keyBy(fn (CompanyCalendarDay $day) => Carbon::parse($day->date)->toDateString());

        // Every date in the range is now known, including the ones with no
        // override - otherwise each of those would still cost a query.
        $cursor = Carbon::parse($from);
        $end = Carbon::parse($to);

        while ($cursor->lte($end)) {
            $date = $cursor->toDateString();
            $this->overrides[$company->id][$date] ??= $days->get($date);
            $cursor->addDay();
        }

        $this->loadedRanges[$key] = true;
    }

    public function override(Company $company, string $date): ?CompanyCalendarDay
    {
        if (array_key_exists($date, $this->overrides[$company->id] ?? [])) {
            return $this->overrides[$company->id][$date];
        }

        return $this->overrides[$company->id][$date] = CompanyCalendarDay::where('company_id', $company->id)
            ->where('date', $date)
            ->first();
    }

    /**
     * Dropped when this request is the one changing the calendar.
     */
    public function forget(int $companyId): void
    {
        unset($this->overrides[$companyId]);

        foreach (array_keys($this->loadedRanges) as $key) {
            if (str_starts_with($key, $companyId.':')) {
                unset($this->loadedRanges[$key]);
            }
        }
    }
}
