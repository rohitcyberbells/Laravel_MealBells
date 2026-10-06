<?php

namespace App\Services\Hrms;

use App\Models\Company;
use App\Models\User;

/**
 * CancelSkip requires a real actor for the audit trail, but a webhook has no
 * authenticated user. The company's primary admin owns the daily count, so a
 * release is attributed to them.
 *
 * Every lookup is filtered by company_id, so another tenant's user can never end
 * up in this company's audit trail.
 */
class HrmsActorResolver
{
    public function forCompany(Company $company): ?User
    {
        $primaryAdminId = $company->setting?->primary_admin_id;

        if ($primaryAdminId) {
            $primaryAdmin = User::where('id', $primaryAdminId)
                ->where('company_id', $company->id)
                ->where('role', 'company_admin')
                ->first();

            if ($primaryAdmin) {
                return $primaryAdmin;
            }
        }

        return User::where('company_id', $company->id)
            ->where('role', 'company_admin')
            ->orderBy('id')
            ->first();
    }
}
