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
use Illuminate\Validation\Rule;
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

        // The action takes rows, not a file: an UploadedFile and a Company were
        // being handed to execute(array $rows), which was a TypeError on every
        // preview. The company is passed so external_id can be checked against
        // employees already in it.
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
            // Optional, because the preview only emits these when the CSV has
            // those columns and ImportEmployeeCsv already defaults them. Requiring
            // them here rejected the very rows the preview had just produced.
            'rows.*.is_meal_eligible' => 'sometimes|boolean',
            'rows.*.status' => 'sometimes|in:active,inactive',
        ]);

        $company = Auth::user()->company;
        $count = $action->execute($company, $request->input('rows'));

        return back()->with('message', "Successfully imported/updated {$count} employees.");
    }

    public function createLogins(Request $request, CreateEmployeeLogins $action)
    {
        $company = Auth::user()->company;

        // 'nullable' still let an empty call through, and the action treats an
        // empty list as "everyone" - so a malformed request silently provisioned
        // logins, and temporary passwords, for every active employee. Selecting
        // is now explicit.
        $validated = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => [
                'integer',
                // Scoped to this company: another tenant's id is a validation
                // error rather than something quietly dropped by the action.
                Rule::exists('employees', 'id')->where('company_id', $company->id),
            ],
        ]);

        $credentials = $action->execute($company, $validated['employee_ids'], Auth::user());

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
