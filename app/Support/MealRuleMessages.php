<?php

namespace App\Support;

use App\Enums\MealRuleReason;

class MealRuleMessages
{
    /**
     * Map MealRuleReason enum code to friendly Hinglish message.
     */
    public static function getMessage(MealRuleReason|string $reason, ?string $defaultMessage = null): string
    {
        $code = $reason instanceof MealRuleReason ? $reason->value : $reason;

        return match ($code) {
            MealRuleReason::CROSS_COMPANY->value => 'Aap doosri company ke records modify nahi kar sakte.',
            MealRuleReason::INACTIVE_EMPLOYEE->value => 'Yeh employee filhaal inactive hai.',
            MealRuleReason::NOT_A_MEAL_DAY->value => 'Yeh din meal day nahi hai (weekend ya holiday).',
            MealRuleReason::PAST_DATE->value => 'Beete hue dino ke liye yeh change nahi ho sakta.',
            MealRuleReason::ADVANCE_LIMIT_EXCEEDED->value => 'Aap advance limit days se aage ki date modify nahi kar sakte.',
            MealRuleReason::COUNT_LOCKED->value => 'Aaj ka count lock ho chuka hai. Cutoff ke baad direct changes disallowed hain.',
            MealRuleReason::COUNT_NOT_LOCKED->value => 'Daily count abhi lock nahi hua hai.',
            MealRuleReason::CUTOFF_PASSED->value => 'Cutoff time beeth chuka hai. Ab regular change nahi ho sakta.',
            MealRuleReason::INVALID_QUANTITY->value => 'Kripya valid quantity enter karein.',
            MealRuleReason::INVALID_TYPE->value => 'Invalid adjustment type.',
            MealRuleReason::INVALID_SOURCE->value => 'Invalid attendance source.',
            MealRuleReason::NEGATIVE_TOTAL->value => 'Adjusted total negative nahi ho sakta. Total meals 0 se kam nahi ho sakti.',
            MealRuleReason::FORBIDDEN_ROLE->value => 'Aapke paas is action ko perform karne ki permission nahi hai.',
            MealRuleReason::EMPLOYEE_CANNOT_CANCEL_SYSTEM_SKIP->value => 'HR ya System dwara kiya gaya skip aap swayam nahi hata sakte. Kripya HR se sampark karein.',
            MealRuleReason::DUPLICATE_RULE->value => 'Same day ke liye active recurring rule pehle se majood hai.',
            default => $defaultMessage ?? 'Action fail ho gaya. Kripya details check karein.',
        };
    }
}
