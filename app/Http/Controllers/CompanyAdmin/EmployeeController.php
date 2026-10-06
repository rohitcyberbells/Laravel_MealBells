<?php

namespace App\Http\Controllers\CompanyAdmin;

use App\Actions\Employee\CreateEmployeeLogins;
use App\Actions\Employee\ImportEmployeeCsv;
use App\Actions\Employee\ResetEmployeePassword;
use App\Actions\Employee\ValidateEmployeeCsv;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $company = $user->company;

        $search = $request->query('search');
        $status = $request->query('status');

        $employees = Employee::where('company_id', $company->id)
            ->when($search, fn ($q) => $q->where(function ($sub) use ($search) {
                $sub->where('name', 'like', "%{$search}%")
                    ->orWhere('employee_code', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            }))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('CompanyAdmin/Employees/Index', [
            'company' => $company,
            'employees' => $employees,
            'filters' => [
                'search' => $search,
                'status' => $status,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $company = Auth::user()->company;

        $allowedSources = implode(',', config('mealbells.attendance_sources', ['manual', 'integrated', 'none']));

        $validated = $request->validate([
            'employee_code' => 'required|string|max:50',
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'attendance_source' => 'nullable|string|in:'.$allowedSources,
            'is_meal_eligible' => 'required|boolean',
            'status' => 'required|in:active,inactive',
        ]);

        // Tenant Isolation Check for Unique Employee Code
        $exists = Employee::where('company_id', $company->id)
            ->where('employee_code', $validated['employee_code'])
            ->exists();

        if ($exists) {
            return back()->withErrors(['employee_code' => 'An employee with this code already exists in your company.']);
        }

        Employee::create(array_merge($validated, ['company_id' => $company->id]));

        return back()->with('message', 'Employee created successfully.');
    }

    public function update(Request $request, Employee $employee)
    {
        $company = Auth::user()->company;
        if ($employee->company_id !== $company->id) {
            abort(403, 'Unauthorized access to employee.');
        }

        $allowedSources = implode(',', config('mealbells.attendance_sources', ['manual', 'integrated', 'none']));

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'attendance_source' => 'nullable|string|in:'.$allowedSources,
            'is_meal_eligible' => 'required|boolean',
            'status' => 'required|in:active,inactive',
        ]);

        $employee->update($validated);

        return back()->with('message', 'Employee updated successfully.');
    }

    public function previewCsv(Request $request, ValidateEmployeeCsv $action)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:2048',
        ]);

        $company = Auth::user()->company;

        // The action takes rows, not a file: previously an UploadedFile was
        // handed to execute(array), which was a TypeError on every preview.
        $rows = $action->parse($request->file('file'));
        $result = $action->execute($rows, $company);

        return back()->with('csvPreview', $result);
    }

    public function importCsv(Request $request, ImportEmployeeCsv $action)
    {
        $request->validate([
            'rows' => 'required|array|min:1',
            'rows.*.employee_code' => 'required|string',
            'rows.*.name' => 'required|string',
            'rows.*.external_id' => 'nullable|string|max:255',
            'rows.*.email' => 'nullable|email',
            'rows.*.is_meal_eligible' => 'required|boolean',
            'rows.*.status' => 'required|in:active,inactive',
        ]);

        $company = Auth::user()->company;
        $count = $action->execute($company, $request->input('rows'));

        return back()->with('message', "Successfully imported/updated {$count} employees.");
    }

    public function createLogins(Request $request, CreateEmployeeLogins $action)
    {
        $company = Auth::user()->company;

        $validated = $request->validate([
            'employee_ids' => 'nullable|array',
            'employee_ids.*' => 'exists:employees,id',
        ]);

        $employeeIds = $validated['employee_ids'] ?? null;

        $credentials = $action->execute($company, $employeeIds, Auth::user());

        return back()->with([
            'message' => 'Generated logins for '.count($credentials).' employees.',
            'credentials' => $credentials,
        ]);
    }

    public function resetPassword(Request $request, Employee $employee, ResetEmployeePassword $action)
    {
        $company = Auth::user()->company;

        $credential = $action->execute($company, $employee, Auth::user());

        return back()->with([
            'message' => "Password reset successfully for employee {$employee->name}.",
            'credentials' => [$credential],
        ]);
    }
}
