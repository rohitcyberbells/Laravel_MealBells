<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyHrmsConnection;
use Illuminate\Http\Request;

class HrmsConnectionController extends Controller
{
    /**
     * Generate or rotate a company's webhook secret.
     *
     * The plaintext is flashed back once and never stored unencrypted, so it
     * cannot be recovered afterwards - rotating again is the only way forward,
     * which is the behaviour we want from a credential.
     */
    public function rotate(Request $request, Company $company)
    {
        $secret = CompanyHrmsConnection::generateSecret();

        CompanyHrmsConnection::updateOrCreate(
            ['company_id' => $company->id],
            [
                'webhook_secret' => $secret,
                'secret_rotated_at' => now(),
                'rotated_by' => $request->user()->id,
            ]
        );

        return back()->with('hrms_secret', [
            'company_id' => $company->id,
            'company_name' => $company->name,
            'webhook_url' => url("/api/hrms/{$company->code}/events"),
            'secret' => $secret,
        ]);
    }
}
