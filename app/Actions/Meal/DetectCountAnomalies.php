<?php

namespace App\Actions\Meal;

use App\Models\Company;
use App\Models\MealCount;

class DetectCountAnomalies
{
    /**
     * Detect anomalies in calculated meal numbers for a company on a given date.
     *
     * @param  array{
     *     base_eligible_count: int,
     *     skip_count: int,
     *     extra_count: int,
     *     final_expected_count: int
     * }  $numbers
     * @return array<string> List of anomaly flag strings (e.g. ['count_deviation', 'spike', 'zero'])
     */
    public function execute(Company $company, string $date, array $numbers): array
    {
        $flags = [];

        $baseCount = (int) ($numbers['base_eligible_count'] ?? 0);
        $skipCount = (int) ($numbers['skip_count'] ?? 0);
        $extraCount = (int) ($numbers['extra_count'] ?? 0);
        $currentCount = (int) ($numbers['final_expected_count'] ?? 0);

        // 1. Zero Anomaly Check
        if ($currentCount === 0 || $baseCount === 0) {
            $flags[] = 'zero';
        }

        // 2. Spike Anomaly Check
        $maxExtraSpike = (int) config('mealbells.anomaly.max_extra_spike', 10);
        $maxSkipRatio = (float) config('mealbells.anomaly.max_skip_ratio', 0.30);

        $isExtraSpike = $extraCount > $maxExtraSpike;
        $isSkipSpike = $baseCount > 0 && ($skipCount / $baseCount) >= $maxSkipRatio;

        if ($isExtraSpike || $isSkipSpike) {
            $flags[] = 'spike';
        }

        // 3. Count Deviation Check (Requires min 3 locked history records)
        $historyMinDays = (int) config('mealbells.anomaly.history_min_days', 3);
        $deviationThresholdPercent = (float) config('mealbells.anomaly.deviation_threshold_percent', 20);

        $historyRecords = MealCount::where('company_id', $company->id)
            ->where('date', '<', $date)
            ->whereNotNull('locked_at')
            ->latest('date')
            ->take(5)
            ->get();

        if ($historyRecords->count() >= $historyMinDays) {
            $avgCount = $historyRecords->avg('adjusted_total');
            if ($avgCount > 0) {
                $percentageDiff = abs($currentCount - $avgCount) / $avgCount * 100;
                if ($percentageDiff >= $deviationThresholdPercent) {
                    $flags[] = 'count_deviation';
                }
            }
        }

        return array_values(array_unique($flags));
    }
}
