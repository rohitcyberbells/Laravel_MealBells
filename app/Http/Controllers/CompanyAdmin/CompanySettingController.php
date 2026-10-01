<?php

namespace App\Http\Controllers\CompanyAdmin;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CompanySettingController extends Controller
{
    public function update(Request $request)
    {
        $user = Auth::user();
        $company = $user->company;

        if (! $company) {
            return back()->withErrors(['company' => 'User is not associated with any company.']);
        }

        $validated = $request->validate([
            'cutoff_time' => 'required|date_format:H:i:s',
            'timezone' => 'required|string|timezone',
            'wfh_auto_skip' => 'required|boolean',
            'meal_days' => 'required|array|min:1',
            'meal_days.*' => 'integer|between:1,7|distinct',
            'primary_admin_id' => 'nullable|exists:users,id',
            'backup_admin_id' => 'nullable|exists:users,id',
        ]);

        CompanySetting::updateOrCreate(
            ['company_id' => $company->id],
            $validated
        );

        return back()->with('message', 'Company settings updated successfully.');
    }
}
