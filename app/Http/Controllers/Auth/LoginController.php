<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class LoginController extends Controller
{
    /** The password every seeded demo account shares. */
    protected const DEMO_PASSWORD = 'demo1234';

    /** One button per role, in the order a demo walks through them. */
    protected const DEMO_ROLES = [
        'super_admin' => 'Super admin',
        'tiffin_admin' => 'Kitchen',
        'company_admin' => 'Company admin',
        'employee' => 'Employee',
    ];

    public function create()
    {
        return Inertia::render('Auth/Login', [
            'demoAccounts' => $this->demoAccounts(),
        ]);
    }

    /**
     * The one-click role buttons, read out of whatever database is loaded.
     *
     * Two bugs, and this answers both.
     *
     * They had no condition at all to begin with, so they rendered in every
     * environment - publishing four working sign-ins, a shared password and a
     * real person's address on the public page of a deployment. That is
     * decided here rather than in the component, because a build-time flag
     * can be wrong and the server always knows what it is.
     *
     * Then the addresses were written into the component, which made them
     * right for exactly one database. Switching between the demo file and a
     * working one left all four buttons filling in accounts that do not
     * exist - and silently, since a button cannot say so until it is pressed.
     * Each one is now a row that is actually there.
     *
     * The shared password is checked rather than assumed, so a database whose
     * accounts have real passwords offers no buttons at all instead of four
     * that cannot work.
     *
     * @return array<int, array{label: string, email: string, password: string}>
     */
    protected function demoAccounts(): array
    {
        if (! app()->environment('local')) {
            return [];
        }

        $accounts = [];

        foreach (self::DEMO_ROLES as $role => $label) {
            $user = User::where('role', $role)
                ->where('is_active', true)
                ->orderBy('id')
                ->first();

            if ($user && Hash::check(self::DEMO_PASSWORD, $user->password)) {
                $accounts[] = [
                    'label' => $label,
                    'email' => $user->email,
                    'password' => self::DEMO_PASSWORD,
                ];
            }
        }

        return $accounts;
    }

    /**
     * Email and password. There is no other way in.
     *
     * Employees used to be able to sign in with a company code and their
     * employee code, for the ones who have no address of their own. That is
     * gone: two ways to prove who you are is two things to explain, two
     * things to get wrong, and the code pair was the weaker of them - it was
     * printed on a handover sheet and never expired.
     *
     * The consequence is deliberate and has a matching guard: an employee
     * with no email address cannot be given a login at all, rather than being
     * given one that cannot be used. See CreateEmployeeLogins.
     *
     * 'identifier' is still accepted alongside 'email' because the form posts
     * that name.
     */
    public function store(Request $request)
    {
        $field = $request->filled('identifier') ? 'identifier' : 'email';

        $validated = $request->validate([
            $field => ['required', 'email'],
            'password' => ['required'],
        ], [
            "{$field}.email" => 'Enter the email address your login was created with.',
        ]);

        return $this->attemptEmailLogin($request, $validated[$field], $field);
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
     * Whether this account may sign in at all.
     *
     * The rule itself lives on the model, because the middleware that re-checks
     * it on every request has to apply exactly the same one. Checked before the
     * password in both login paths, so a deactivated account is refused in a
     * way indistinguishable from a wrong address - saying "deactivated" would
     * confirm the account exists.
     */
    protected function employeeIsActive(User $user): bool
    {
        return $user->canSignIn();
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
            // Preparation, not the dashboard. A kitchen signs in to find out how
            // many tiffins to cook; setting next week's menu is a weekly job it
            // can navigate to. The dashboard led with the menu editor, so the
            // one number the vendor opened the app for was a click away.
            'tiffin_admin' => '/tiffin-admin/preparation',
            'company_admin' => '/company-admin/dashboard',
            'employee' => '/employee/dashboard',
            default => '/',
        });
    }

    public function showChangePassword()
    {
        return Inertia::render('Auth/ChangePassword', [
            // The same screen serves two situations that read very
            // differently: being stopped at the door because somebody else
            // chose this password, and choosing to change one yourself.
            'forced' => (bool) Auth::user()->must_change_password,
        ]);
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|current_password',
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $user = Auth::user();
        $wasForced = (bool) $user->must_change_password;

        $user->update([
            'password' => Hash::make($request->password),
            'must_change_password' => false,
        ]);

        // Somebody sent here at sign-in is going on to their dashboard; the
        // landing redirect already knows where that is per role. Somebody who
        // came here on purpose was in the middle of something, so they go
        // back to it.
        return ($wasForced ? redirect('/') : back())
            ->with('message', 'Password changed.');
    }

    public function destroy(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
