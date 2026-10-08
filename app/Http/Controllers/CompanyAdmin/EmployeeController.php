<?php

namespace App\Http\Controllers\CompanyAdmin;

use App\Actions\Employee\CreateEmployeeLogins;
use App\Actions\Employee\ImportEmployeeCsv;
use App\Actions\Employee\ResetEmployeePassword;
use App\Actions\Employee\ValidateEmployeeCsv;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\RecurringSkip;
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
            // Lowered on both sides rather than a plain LIKE.
            //
            // SQLite's LIKE is case-insensitive for ASCII and PostgreSQL's is
            // not, so this screen searched case-insensitively in development
            // and case-sensitively in production: typing 'alice' would not
            // find 'Alice' on a real deployment, and nothing in the suite
            // would ever have said so.
            ->when($search, fn ($q) => $q->where(function ($sub) use ($search) {
                $needle = '%'.mb_strtolower(trim((string) $search)).'%';

                $sub->whereRaw('LOWER(name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(employee_code) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$needle]);
            }))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        // Only for the rows on this page, so the roster does not pull every
        // rule in the company to render fifteen of them.
        $recurringRules = RecurringSkip::where('company_id', $company->id)
            ->whereIn('employee_id', $employees->getCollection()->pluck('id'))
            ->orderBy('weekday')
            ->get(['id', 'employee_id', 'weekday', 'starts_on', 'ends_on', 'active'])
            ->groupBy('employee_id');

        return Inertia::render('CompanyAdmin/Employees/Index', [
            'company' => $company,
            'employees' => $employees,
            'recurring_rules' => $recurringRules,
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
            // The uploaded file is capped at 2MB, but this endpoint takes the
            // previewed rows as JSON, not the file - so without a cap here a
            // crafted request could hand the importer an unbounded array to
            // process in one transaction.
            'rows' => ['required', 'array', 'min:1', 'max:'.config('mealbells.max_import_rows', 2000)],
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

        // The action reports counts per outcome. Interpolating the whole array
        // into the message threw "Array to string conversion", so the import
        // landed the rows and then answered with a 500.
        $result = $action->execute($company, $request->input('rows'));

        $parts = [];

        if ($result['imported'] > 0) {
            $parts[] = $result['imported'].' added';
        }

        if ($result['updated'] > 0) {
            $parts[] = $result['updated'].' updated';
        }

        if ($result['unchanged'] > 0) {
            $parts[] = $result['unchanged'].' unchanged';
        }

        // Rows the importer itself refused, as opposed to the ones the preview
        // had already filtered out. Saying so beats a count that quietly
        // excludes them.
        if ($result['errors'] !== []) {
            $parts[] = count($result['errors']).' failed';
        }

        $message = $parts === []
            ? 'Nothing to import: no rows were supplied.'
            : 'Import finished: '.implode(', ', $parts).'.';

        return back()->with('message', $message);
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
