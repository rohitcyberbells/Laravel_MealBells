<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class LoginController extends Controller
{
    public function create()
    {
        return Inertia::render('Auth/Login');
    }

    /**
     * One form, three accepted shapes.
     *
     * The page posts a single 'identifier': an address signs in by email, and
     * anything else is treated as an employee code and paired with the company
     * code. The two older shapes - explicit company_code + login_code, and a
     * plain email field - still work, so existing clients keep going.
     */
    public function store(Request $request)
    {
        if ($request->filled('identifier')) {
            $identifier = trim((string) $request->input('identifier'));

            return str_contains($identifier, '@')
                ? $this->attemptEmailLogin($request, $identifier, 'identifier')
                : $this->attemptCodeLogin($request, (string) $request->input('company_code'), $identifier);
        }

        if ($request->filled('company_code') || $request->filled('login_code')) {
            $validated = $request->validate([
                'company_code' => ['required', 'string'],
                'login_code' => ['required', 'string'],
                'password' => ['required'],
            ]);

            return $this->attemptCodeLogin($request, $validated['company_code'], $validated['login_code']);
        }

        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        return $this->attemptEmailLogin($request, $validated['email'], 'email');
    }

    /**
     * Email and password, for every role including employees.
     *
     * Every failure returns the same message on purpose: a different response
     * for "no such address" would turn this form into a way to find out which
     * employees are registered.
     */
    protected function attemptEmailLogin(Request $request, string $email, string $field)
    {
        $request->validate(['password' => ['required']]);

        $user = User::where('email', $email)->first();

        // Status is checked before the password so an inactive employee never
        // gets a session, and the refusal is indistinguishable from a wrong
        // address. The code path already refused these; email did not.
        if (! $user || ! $this->employeeIsActive($user)) {
            return $this->genericFailure($request, $field);
        }

        if (! Auth::attempt(['id' => $user->id, 'password' => $request->input('password')], $request->boolean('remember'))) {
            return $this->genericFailure($request, $field);
        }

        return $this->completeLogin($request, $user);
    }

    /**
     * Company code plus employee code. Messages here stay specific, because an
     * employee code is only meaningful to someone who already holds it.
     */
    protected function attemptCodeLogin(Request $request, string $companyCode, string $loginCode)
    {
        $request->validate(['password' => ['required']]);

        if (trim($companyCode) === '') {
            return back()
                ->withErrors(['company_code' => 'Company code is required when signing in with an employee code.'])
                ->onlyInput('identifier', 'company_code', 'login_code');
        }

        $company = Company::where('code', strtoupper(trim($companyCode)))->first();

        if (! $company) {
            return back()->withErrors(['company_code' => 'Invalid Company Code.'])
                ->onlyInput('identifier', 'company_code', 'login_code');
        }

        $user = User::where('company_id', $company->id)
            ->where('login_code', strtoupper(trim($loginCode)))
            ->where('role', 'employee')
            ->first();

        if (! $user) {
            return back()->withErrors(['login_code' => 'Invalid Employee Code or Account.'])
                ->onlyInput('identifier', 'company_code', 'login_code');
        }

        if (! $this->employeeIsActive($user)) {
            return back()->withErrors(['login_code' => 'Your employee account is inactive.'])
                ->onlyInput('identifier', 'company_code', 'login_code');
        }

        if (! Auth::attempt(['id' => $user->id, 'password' => $request->input('password')], $request->boolean('remember'))) {
            return back()->withErrors(['password' => 'Invalid Password.'])
                ->onlyInput('identifier', 'company_code', 'login_code');
        }

        return $this->completeLogin($request, $user);
    }

    /**
     * Whether this account may sign in at all.
     *
     * Two separate switches, because they are owned by different people: an
     * employee is stood down on their employee record by their HR team, and any
     * account can be deactivated by a super admin.
     *
     * Checked before the password in both login paths, so a deactivated account
     * is refused in a way indistinguishable from a wrong address - saying
     * "deactivated" would confirm the account exists.
     */
    protected function employeeIsActive(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        // An archived company's people cannot sign in. The company is
        // soft-deleted, so the relation resolves to null while company_id still
        // points at it - which is exactly the condition to refuse.
        if ($user->company_id !== null && $user->company === null) {
            return false;
        }

        if ($user->role !== 'employee') {
            return true;
        }

        return ! $user->employee || $user->employee->status === 'active';
    }

    protected function genericFailure(Request $request, string $field)
    {
        return back()
            ->withErrors([$field => 'These credentials do not match our records.'])
            ->onlyInput('identifier', 'email', 'company_code');
    }

    protected function completeLogin(Request $request, User $user)
    {
        $user->update(['last_login_at' => now()]);
        $request->session()->regenerate();

        return redirect()->intended(match ($user->role) {
            'super_admin' => '/super-admin/dashboard',
            'tiffin_admin' => '/tiffin-admin/dashboard',
            'company_admin' => '/company-admin/dashboard',
            'employee' => '/employee/dashboard',
            default => '/',
        });
    }

    public function showChangePassword()
    {
        return Inertia::render('Auth/ChangePassword');
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|current_password',
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $user = Auth::user();
        $user->update([
            'password' => Hash::make($request->password),
            'must_change_password' => false,
        ]);

        return redirect('/')->with('message', 'Password changed successfully!');
    }

    public function destroy(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
