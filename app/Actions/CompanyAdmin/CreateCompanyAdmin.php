<?php

namespace App\Actions\CompanyAdmin;

use App\Enums\MealRuleReason;
use App\Exceptions\MealRuleViolation;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateCompanyAdmin
{
    /**
     * Create a new company admin for the given company.
     *
     * @return array{user: User, temporary_password: string}
     */
    public function execute(Company $company, string $name, string $email, User $creator): array
    {
        // 1. Cross-Company Authorization check
        if ($creator->company_id !== $company->id) {
            throw new MealRuleViolation('Cannot create admin for another company.', MealRuleReason::CROSS_COMPANY);
        }

        // 2. Role Check
        if ($creator->role !== 'company_admin' && $creator->role !== 'super_admin') {
            throw new MealRuleViolation('Only company admins can invite company admins.', MealRuleReason::FORBIDDEN_ROLE);
        }

        // 3. Unique Email Check
        if (User::where('email', $email)->exists()) {
            throw new MealRuleViolation("User with email {$email} already exists.", MealRuleReason::INVALID_QUANTITY);
        }

        $tempPassword = Str::random(12);

        $user = User::create([
            'company_id' => $company->id,
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($tempPassword),
            'role' => 'company_admin',
            'must_change_password' => true,
        ]);

        return [
            'user' => $user,
            'temporary_password' => $tempPassword,
        ];
    }
}
