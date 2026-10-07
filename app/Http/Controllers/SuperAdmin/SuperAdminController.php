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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
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

        $tiffinServices = TiffinService::all();

        // The admin accounts this screen can reset a password for. Employees are
        // deliberately absent: their own company admin resets those, and listing
        // every employee here would bury the handful of accounts that matter.
        $admins = User::whereIn('role', ['company_admin', 'tiffin_admin'])
            ->select('id', 'name', 'email', 'role', 'company_id', 'tiffin_service_id', 'must_change_password', 'is_active', 'deactivated_at')
            ->orderBy('name')
            ->get();

        $companyAdmins = $admins->where('role', 'company_admin')->groupBy('company_id');
        $tiffinAdmins = $admins->where('role', 'tiffin_admin')->groupBy('tiffin_service_id');

        return Inertia::render('SuperAdmin/Dashboard', [
            'companies' => $companies->map(fn (Company $company) => [
                ...$company->toArray(),
                'admins' => $companyAdmins->get($company->id, collect())->values(),
                'hrms' => [
                    'webhook_url' => url("/api/hrms/{$company->code}/events"),
                    'has_secret' => (bool) $connections->get($company->id)?->webhook_secret,
                    'secret_rotated_at' => $connections->get($company->id)?->secret_rotated_at?->toDateTimeString(),
                ],
            ]),
            // Listed so an archive is visible and reversible rather than just
            // gone from the page.
            'archivedCompanies' => Company::onlyTrashed()
                ->with('deletedBy:id,name')
                ->get()
                ->map(fn (Company $company) => [
                    'id' => $company->id,
                    'name' => $company->name,
                    'code' => $company->code,
                    'deleted_at' => $company->deleted_at?->toDateTimeString(),
                    'deleted_by' => $company->deletedBy?->name,
                ]),
            'tiffinServices' => $tiffinServices->map(fn (TiffinService $tiffin) => [
                ...$tiffin->toArray(),
                'admins' => $tiffinAdmins->get($tiffin->id, collect())->values(),
            ]),
            'assignments' => CompanyTiffinAssignment::with(['company', 'tiffinService'])->latest()->get(),
            // Flash data, so it survives exactly one render after rotation.
            'hrms_secret' => session('hrms_secret'),
            // Likewise for a password reset. Without this the reset flashed a
            // password that no render ever read, so the only copy was lost and
            // the account was locked behind a password nobody knew.
            'temporary_password' => session('temporary_password'),
            'reset_for' => session('reset_for'),
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
            'admin_password' => ['nullable', 'string', Password::defaults()],
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
            'admin_password' => ['nullable', 'string', Password::defaults()],
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

    /**
     * Archive a company. Nothing is destroyed.
     *
     * A hard delete cascaded across eleven tables, meal_counts among them -
     * the record of what the kitchen was actually told, and the evidence in any
     * billing dispute. A soft delete issues no DELETE at all, so the foreign
     * keys stay quiet and every child row survives.
     *
     * Its users are deactivated rather than deleted, so their attribution on
     * past skips and counts stays intact and the whole thing is reversible.
     */
    public function destroyCompany(Request $request, Company $company)
    {
        // Typed, not clicked. A JS confirm() is one keystroke away from
        // archiving the wrong tenant, and this is the most destructive action
        // in the application.
        $request->validate([
            'confirm_name' => ['required', 'string'],
        ]);

        if (trim($request->input('confirm_name')) !== $company->name) {
            return back()->withErrors([
                'confirm_name' => "That does not match. Type the company's name exactly: {$company->name}",
            ]);
        }

        DB::transaction(function () use ($company, $request) {
            User::where('company_id', $company->id)->update([
                'is_active' => false,
                'deactivated_at' => now(),
                'deactivated_by' => $request->user()->id,
            ]);

            CompanyTiffinAssignment::where('company_id', $company->id)->update(['is_active' => false]);

            $company->forceFill(['deleted_by' => $request->user()->id])->save();
            $company->delete();
        });

        return back()->with('message', "{$company->name} is archived. Nothing was deleted, and it can be restored.");
    }

    public function restoreCompany(Request $request, int $companyId)
    {
        $company = Company::onlyTrashed()->findOrFail($companyId);

        DB::transaction(function () use ($company) {
            $company->restore();
            $company->forceFill(['deleted_by' => null])->save();

            // Their access comes back, but each account still has to be
            // reactivated deliberately - restoring the company is not a
            // decision about who should be able to sign in.
            User::where('company_id', $company->id)
                ->whereNotNull('deactivated_at')
                ->update(['is_active' => true, 'deactivated_at' => null, 'deactivated_by' => null]);
        });

        return back()->with('message', "{$company->name} is restored, and its users can sign in again.");
    }

    /**
     * Turn an account's access on or off.
     *
     * The alternative was deleting the row, which cascades and cannot be
     * undone, or quietly changing the password - neither of which leaves a
     * record of the decision.
     */
    public function setActive(Request $request, User $user)
    {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        // Locking yourself out of the only account that can unlock accounts is
        // a one-way door, so it is refused rather than confirmed.
        if ($user->id === $request->user()->id) {
            return back()->withErrors(['is_active' => 'You cannot deactivate your own account.']);
        }

        $active = (bool) $validated['is_active'];

        $user->forceFill([
            'is_active' => $active,
            'deactivated_at' => $active ? null : now(),
            'deactivated_by' => $active ? null : $request->user()->id,
        ])->save();

        return back()->with('message', $active
            ? "{$user->name} can sign in again."
            : "{$user->name} can no longer sign in. Their records are untouched.");
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
            // Which account the password above belongs to. Resetting two admins
            // in a row otherwise leaves a password on screen with nothing
            // tying it to a person.
            'reset_for' => ['name' => $user->name, 'email' => $user->email],
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
