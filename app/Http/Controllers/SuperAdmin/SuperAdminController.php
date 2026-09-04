<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyTiffinAssignment;
use App\Models\TiffinService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

class SuperAdminController extends Controller
{
    public function index()
    {
        return Inertia::render('SuperAdmin/Dashboard', [
            'companies' => Company::with('assignments.tiffinService')->get(),
            'tiffinServices' => TiffinService::all(),
            'assignments' => CompanyTiffinAssignment::with(['company', 'tiffinService'])->latest()->get(),
        ]);
    }

    public function storeCompany(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'nullable|string',
            'contact_phone' => 'nullable|string|max:50',
            'admin_name' => 'required|string|max:255',
            'admin_email' => 'required|email|unique:users,email',
            'admin_password' => 'required|string|min:6',
        ]);

        $company = Company::create([
            'name' => $validated['name'],
            'address' => $validated['address'],
            'contact_phone' => $validated['contact_phone'],
        ]);

        User::create([
            'name' => $validated['admin_name'],
            'email' => $validated['admin_email'],
            'password' => Hash::make($validated['admin_password']),
            'role' => 'company_admin',
            'company_id' => $company->id,
        ]);

        return back()->with('message', 'Company & Admin created successfully!');
    }

    public function storeTiffin(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'nullable|string',
            'contact_phone' => 'nullable|string|max:50',
            'admin_name' => 'required|string|max:255',
            'admin_email' => 'required|email|unique:users,email',
            'admin_password' => 'required|string|min:6',
        ]);

        $tiffin = TiffinService::create([
            'name' => $validated['name'],
            'address' => $validated['address'],
            'contact_phone' => $validated['contact_phone'],
        ]);

        User::create([
            'name' => $validated['admin_name'],
            'email' => $validated['admin_email'],
            'password' => Hash::make($validated['admin_password']),
            'role' => 'tiffin_admin',
            'tiffin_service_id' => $tiffin->id,
        ]);

        return back()->with('message', 'Tiffin Service & Admin created successfully!');
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
}
