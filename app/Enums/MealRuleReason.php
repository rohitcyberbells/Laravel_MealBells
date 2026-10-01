<?php

namespace App\Enums;

enum MealRuleReason: string
{
    case CROSS_COMPANY = 'cross_company';
    case INACTIVE_EMPLOYEE = 'inactive_employee';
    case NOT_A_MEAL_DAY = 'not_a_meal_day';
    case PAST_DATE = 'past_date';
    case ADVANCE_LIMIT_EXCEEDED = 'advance_limit_exceeded';
    case COUNT_LOCKED = 'count_locked';
    case COUNT_NOT_LOCKED = 'count_not_locked';
    case CUTOFF_PASSED = 'cutoff_passed';
    case INVALID_QUANTITY = 'invalid_quantity';
    case INVALID_TYPE = 'invalid_type';
    case NEGATIVE_TOTAL = 'negative_total';
    case FORBIDDEN_ROLE = 'forbidden_role';
}
