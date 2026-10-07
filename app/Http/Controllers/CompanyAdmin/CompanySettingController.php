<?php

namespace App\Http\Controllers\CompanyAdmin;

use App\Actions\CompanyAdmin\CreateCompanyAdmin;
use App\Exceptions\MealRuleViolation;
use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\User;
use App\Support\MealRuleMessages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class CompanySettingController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $company = $user->company;

        if (! $company) {
            abort(404, 'Company not found.');
        }

        $setting = $company->setting()->with('updatedBy:id,name')->first();

        $companyAdmins = User::where('company_id', $company->id)
            ->where('role', 'company_admin')
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

        return Inertia::render('CompanyAdmin/Settings/Index', [
            'cutoff_time' => $setting?->cutoff_time ?? '11:00',
            'timezone' => $setting?->timezone ?? 'Asia/Kolkata',
            'wfh_auto_skip' => $setting?->wfh_auto_skip ?? false,
            'meal_days' => $setting?->meal_days ?? [1, 2, 3, 4, 5],
            'primary_admin_id' => $setting?->primary_admin_id,
            'backup_admin_id' => $setting?->backup_admin_id,
            'company_admins' => $companyAdmins,
            // Read-only context. The advance limit is a platform setting rather
            // than a company one, and attendance_source lives on each employee,
            // so neither belongs in this form - but the screen explains where
            // they come from instead of leaving them unexplained.
            'advance_limit_days' => (int) config('mealbells.advance_limit_days', 60),
            'attendance_sources' => config('mealbells.attendance_sources', ['manual', 'integrated', 'none']),
            'temporary_password' => session('temporary_password'),
            // So an admin can see who last moved the cutoff, which is the
            // change most likely to need explaining afterwards.
            'last_changed' => $setting?->settings_changed_at ? [
                'at' => $setting->settings_changed_at->toDateTimeString(),
                'by' => $setting->updatedBy?->name,
            ] : null,
        ]);
    }

    public function update(Request $request)
    {
        $user = Auth::user();
        $company = $user->company;

        if (! $company) {
            return back()->withErrors(['company' => 'User is not associated with any company.']);
        }

        $validated = $request->validate([
            'cutoff_time' => 'required',
            'timezone' => 'required|string|timezone',
            'wfh_auto_skip' => 'required|boolean',
            'meal_days' => 'required|array|min:1',
            'meal_days.*' => 'integer|between:1,7|distinct',
            'primary_admin_id' => 'nullable|exists:users,id',
            'backup_admin_id' => 'nullable|exists:users,id',
        ]);

        // Validate primary & backup admin belong to THIS company and have company_admin role
        foreach (['primary_admin_id', 'backup_admin_id'] as $adminKey) {
            if (! empty($validated[$adminKey])) {
                $targetAdmin = User::find($validated[$adminKey]);
                if (! $targetAdmin || $targetAdmin->company_id !== $company->id || $targetAdmin->role !== 'company_admin') {
                    return back()->withErrors([$adminKey => 'Admin must be a valid company admin of your company.']);
                }
            }
        }

        // Attributed, because this is the highest-leverage record there is:
        // moving the cutoff or dropping a meal day changes every future count
        // for the whole company. settings_changed_at is kept separate from
        // updated_at, which also moves when nothing a person did was involved.
        CompanySetting::updateOrCreate(
            ['company_id' => $company->id],
            [...$validated, 'updated_by' => $user->id, 'settings_changed_at' => now()],
        );

        return back()->with('message', 'Company settings updated successfully.');
    }

    public function storeAdmin(Request $request, CreateCompanyAdmin $action)
    {
        $user = Auth::user();
        $company = $user->company;

        if (! $company) {
            return back()->withErrors(['company' => 'User is not associated with any company.']);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
        ]);

        try {
            $result = $action->execute($company, $validated['name'], $validated['email'], $user);

            return back()
                ->with('temporary_password', $result['temporary_password'])
                ->with('message', "Admin created successfully. Temporary Password: {$result['temporary_password']}");
        } catch (MealRuleViolation $e) {
            $msg = MealRuleMessages::getMessage($e->getReasonCode(), $e->getMessage());

            return back()->withErrors(['email' => $msg]);
        }
    }
}
