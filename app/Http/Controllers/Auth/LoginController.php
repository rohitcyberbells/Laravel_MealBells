<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

class LoginController extends Controller
{
    public function create()
    {
        return Inertia::render('Auth/Login');
    }

    public function store(Request $request)
    {
        // 1. Employee Login Flow (company_code + login_code)
        if ($request->filled('company_code') || $request->filled('login_code')) {
            $validated = $request->validate([
                'company_code' => ['required', 'string'],
                'login_code' => ['required', 'string'],
                'password' => ['required'],
            ]);

            $company = Company::where('code', strtoupper(trim($validated['company_code'])))->first();
            if (! $company) {
                return back()->withErrors(['company_code' => 'Invalid Company Code.'])->onlyInput('company_code', 'login_code');
            }

            $user = User::where('company_id', $company->id)
                ->where('login_code', strtoupper(trim($validated['login_code'])))
                ->where('role', 'employee')
                ->first();

            if (! $user) {
                return back()->withErrors(['login_code' => 'Invalid Employee Code or Account.'])->onlyInput('company_code', 'login_code');
            }

            // Inactive employee login rejection
            if ($user->employee && $user->employee->status !== 'active') {
                return back()->withErrors(['login_code' => 'Your employee account is inactive.'])->onlyInput('company_code', 'login_code');
            }

            if (Auth::attempt(['id' => $user->id, 'password' => $validated['password']], $request->boolean('remember'))) {
                $user->update(['last_login_at' => now()]);
                $request->session()->regenerate();

                return redirect()->intended('/employee/dashboard');
            }

            return back()->withErrors(['password' => 'Invalid Password.'])->onlyInput('company_code', 'login_code');
        }

        // 2. Standard Email Login Flow
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $user = Auth::user();
            $user->update(['last_login_at' => now()]);
            $request->session()->regenerate();

            return match ($user->role) {
                'super_admin' => redirect()->intended('/super-admin/dashboard'),
                'tiffin_admin' => redirect()->intended('/tiffin-admin/dashboard'),
                'company_admin' => redirect()->intended('/company-admin/dashboard'),
                'employee' => redirect()->intended('/employee/dashboard'),
                default => redirect('/'),
            };
        }

        return back()->withErrors([
            'email' => 'Invalid Email or Password.',
        ])->onlyInput('email');
    }

    public function showChangePassword()
    {
        return Inertia::render('Auth/ChangePassword');
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|current_password',
            'password' => 'required|string|min:8|confirmed',
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
