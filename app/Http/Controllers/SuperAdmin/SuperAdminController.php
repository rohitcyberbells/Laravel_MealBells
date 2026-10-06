<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyHrmsConnection;
use App\Models\CompanyTiffinAssignment;
use App\Models\DailyOverrides;
use App\Models\TiffinService;
use App\Models\User;
use App\Models\WeeklyMenu;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;

class SuperAdminController extends Controller
{
    public function index()
    {
        $companies = Company::with('assignments.tiffinService')->get();

        // Never the secret itself - only whether one exists and when it changed.
        $connections = CompanyHrmsConnection::whereIn('company_id', $companies->pluck('id'))
            ->get()
            ->keyBy('company_id');

        return Inertia::render('SuperAdmin/Dashboard', [
            'companies' => $companies->map(fn (Company $company) => [
                ...$company->toArray(),
                'hrms' => [
                    'webhook_url' => url("/api/hrms/{$company->code}/events"),
                    'has_secret' => (bool) $connections->get($company->id)?->webhook_secret,
                    'secret_rotated_at' => $connections->get($company->id)?->secret_rotated_at?->toDateTimeString(),
                ],
            ]),
            'tiffinServices' => TiffinService::all(),
            'assignments' => CompanyTiffinAssignment::with(['company', 'tiffinService'])->latest()->get(),
            // Flash data, so it survives exactly one render after rotation.
            'hrms_secret' => session('hrms_secret'),
        ]);
    }

    public function storeCompany(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'required|string|max:500',
            'contact_phone' => ['required', 'string', 'regex:/^(\+?[0-9]{1,4}[\-\s]?)?[0-9]{10}$/'],
            'admin_name' => 'required|string|max:255',
            'admin_email' => 'required|email|unique:users,email',
            'admin_password' => 'nullable|string|min:6',
        ], [
            'contact_phone.regex' => 'Please enter a valid 10-digit phone number (e.g. 9876543210 or +919876543210).',
        ]);

        $company = Company::create([
            'name' => $validated['name'],
            'address' => $validated['address'],
            'contact_phone' => $validated['contact_phone'],
        ]);

        $plainPassword = ! empty($validated['admin_password']) ? $validated['admin_password'] : Str::random(12);

        User::create([
            'name' => $validated['admin_name'],
            'email' => $validated['admin_email'],
            'password' => Hash::make($plainPassword),
            'role' => 'company_admin',
            'company_id' => $company->id,
            'must_change_password' => true,
        ]);

        return back()->with([
            'message' => 'Company & Admin created successfully!',
            'temporary_password' => $plainPassword,
        ]);
    }

    public function storeTiffin(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'required|string|max:500',
            'contact_phone' => ['required', 'string', 'regex:/^(\+?[0-9]{1,4}[\-\s]?)?[0-9]{10}$/'],
            'admin_name' => 'required|string|max:255',
            'admin_email' => 'required|email|unique:users,email',
            'admin_password' => 'nullable|string|min:6',
        ], [
            'contact_phone.regex' => 'Please enter a valid 10-digit phone number (e.g. 9876543210 or +919876543210).',
        ]);

        $tiffin = TiffinService::create([
            'name' => $validated['name'],
            'address' => $validated['address'],
            'contact_phone' => $validated['contact_phone'],
        ]);

        $plainPassword = ! empty($validated['admin_password']) ? $validated['admin_password'] : Str::random(12);

        User::create([
            'name' => $validated['admin_name'],
            'email' => $validated['admin_email'],
            'password' => Hash::make($plainPassword),
            'role' => 'tiffin_admin',
            'tiffin_service_id' => $tiffin->id,
            'must_change_password' => true,
        ]);

        return back()->with([
            'message' => 'Tiffin Service & Admin created successfully!',
            'temporary_password' => $plainPassword,
        ]);
    }

    public function assign(Request $request)
    {
        $validated = $request->validate([
            'company_id' => 'required|exists:companies,id',
            'tiffin_service_id' => 'required|exists:tiffin_services,id',
        ]);

        // Deactivate old active assignments for this company
        CompanyTiffinAssignment::where('company_id', $validated['company_id'])
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'unassigned_at' => now(),
            ]);

        // Create new active assignment
        CompanyTiffinAssignment::create([
            'company_id' => $validated['company_id'],
            'tiffin_service_id' => $validated['tiffin_service_id'],
            'is_active' => true,
            'assigned_at' => now(),
        ]);

        return back()->with('message', 'Company successfully paired with Tiffin Service!');
    }

    public function unpair(Request $request)
    {
        $validated = $request->validate([
            'company_id' => 'required|exists:companies,id',
        ]);
        CompanyTiffinAssignment::where('company_id', $validated['company_id'])
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'unassigned_at' => now(),
            ]);

        return back()->with('message', 'Company unpaired successfully!');
    }

    public function destroyCompany(Company $company)
    {
        User::where('company_id', $company->id)->delete();
        CompanyTiffinAssignment::where('company_id', $company->id)->delete();
        $company->delete();

        return back()->with('message', 'Company deleted successfully!');
    }

    public function resetPassword(Request $request, User $user)
    {
        $newTempPassword = Str::random(12);

        $user->update([
            'password' => Hash::make($newTempPassword),
            'must_change_password' => true,
        ]);

        return back()->with([
            'message' => "Password reset successfully for {$user->name}.",
            'temporary_password' => $newTempPassword,
        ]);
    }

    public function destroyTiffin(TiffinService $tiffinService)
    {
        User::where('tiffin_service_id', $tiffinService->id)->delete();
        CompanyTiffinAssignment::where('tiffin_service_id', $tiffinService->id)->delete();
        WeeklyMenu::where('tiffin_service_id', $tiffinService->id)->delete();
        DailyOverrides::where('tiffin_service_id', $tiffinService->id)->delete();
        $tiffinService->delete();

        return back()->with('message', 'Tiffin Service deleted successfully!');
    }
}
