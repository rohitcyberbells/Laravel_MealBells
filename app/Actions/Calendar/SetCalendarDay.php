<?php

namespace App\Actions\Calendar;

use App\Enums\MealRuleReason;
use App\Exceptions\MealRuleViolation;
use App\Models\Company;
use App\Models\CompanyCalendarDay;
use App\Models\MealCount;
use App\Models\User;
use Carbon\Carbon;

class SetCalendarDay
{
    public function execute(
        Company $company,
        string $date,
        string $type,
        ?string $note = null,
        ?User $createdBy = null
    ): CompanyCalendarDay {
        $timezone = $company->setting?->timezone ?? 'Asia/Kolkata';
        $todayStr = Carbon::today($timezone)->toDateString();

        if ($date < $todayStr) {
            throw new MealRuleViolation('Cannot modify past calendar dates.', MealRuleReason::PAST_DATE);
        }

        $isLocked = MealCount::where('company_id', $company->id)
            ->where('date', $date)
            ->whereNotNull('locked_at')
            ->exists();

        if ($isLocked) {
            throw new MealRuleViolation('Cannot modify calendar for locked date.', MealRuleReason::COUNT_LOCKED);
        }

        if (! in_array($type, ['holiday', 'working_day'], true)) {
            throw new MealRuleViolation('Invalid calendar day type.', MealRuleReason::INVALID_TYPE);
        }

        return CompanyCalendarDay::updateOrCreate(
            [
                'company_id' => $company->id,
                'date' => $date,
            ],
            [
                'type' => $type,
                'note' => $note,
                'created_by' => $createdBy?->id,
            ]
        );
    }
}
