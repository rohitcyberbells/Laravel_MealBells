<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\PasswordResetLinkNotification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Inertia\Inertia;

/**
 * Forgotten passwords, for anyone whose account can receive mail.
 *
 * Two deliberate choices run through this:
 *
 * 1. Every request is answered identically. Saying "no such account" would turn
 *    this form into a way to find out which addresses are registered - and in a
 *    company's meal portal, that is a list of who works there.
 *
 * 2. An employee with no address of their own carries a stand-in one that is
 *    never written to. Sending a link there would leave them waiting for a mail
 *    that cannot arrive, so those accounts are passed over and their HR team
 *    resets them instead - which is the path that already exists.
 */
class PasswordResetController extends Controller
{
    /**
     * The same answer in every case, successful or not.
     */
    protected const GENERIC_RESPONSE = 'If that address has an account, a reset link is on its way. Check your inbox, including spam.';

    public function showRequestForm()
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    public function sendResetLink(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        // The broker is only invoked for an account that could actually be
        // reached. Everything else - no such account, a stand-in address, a
        // deactivated user - falls through to the same answer.
        if ($user && $user->canReceiveMail() && $this->isReachable($user)) {
            $token = Password::broker()->createToken($user);

            $user->notify(new PasswordResetLinkNotification($token));
        }

        return back()->with('message', self::GENERIC_RESPONSE);
    }

    public function showResetForm(Request $request, string $token)
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    // They have just chosen it themselves, so the forced-change
                    // gate has nothing left to ask for.
                    'must_change_password' => false,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PasswordReset) {
            return redirect()->route('login')
                ->with('message', 'Your password has been changed. Please sign in.');
        }

        // An expired or already-used token, or an address that does not match
        // the one the token was issued for.
        return back()->withErrors([
            'email' => 'That reset link is no longer valid. Please request a new one.',
        ]);
    }

    /**
     * A deactivated account is not sent a link either: it could not sign in
     * with the new password anyway.
     *
     * Both switches are checked, because they are owned by different people -
     * a super admin deactivates the account, an HR team stands an employee down
     * on their employee record.
     */
    protected function isReachable(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->role === 'employee') {
            return ! $user->employee || $user->employee->status === 'active';
        }

        return true;
    }
}
