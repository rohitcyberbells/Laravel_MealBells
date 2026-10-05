<?php

namespace App\Http\Controllers\CompanyAdmin;

use App\Actions\Meal\BulkRecordSkip;
use App\Actions\Meal\CancelSkip;
use App\Actions\Meal\RecordSkip;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Skip;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SkipController extends Controller
{
    public function store(Request $request, RecordSkip $action)
    {
        $company = Auth::user()->company;

        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'date' => 'required|date_format:Y-m-d',
            'source' => 'required|string|in:leave,wfh,hr,self,link,recurring',
            'reason' => 'nullable|string|max:255',
        ]);

        $employee = Employee::where('company_id', $company->id)
            ->where('id', $validated['employee_id'])
            ->firstOrFail();

        try {
            $action->execute($company, $employee, $validated['date'], $validated['source'], $validated['reason'], Auth::user());
        } catch (Exception $e) {
            return back()->withErrors(['skip' => $e->getMessage()]);
        }

        return back()->with('message', 'Skip recorded successfully.');
    }

    public function destroy(Skip $skip, CancelSkip $action)
    {
        $company = Auth::user()->company;

        if ($skip->company_id !== $company->id) {
            abort(403, 'Unauthorized access to skip record.');
        }

        try {
            $action->execute($company, $skip, Auth::user());
        } catch (Exception $e) {
            return back()->withErrors(['skip' => $e->getMessage()]);
        }

        return back()->with('message', 'Skip cancelled successfully.');
    }

    public function bulkStore(Request $request, BulkRecordSkip $action)
    {
        $company = Auth::user()->company;

        $validated = $request->validate([
            'employee_ids' => 'required|array|min:1',
            'employee_ids.*' => 'exists:employees,id',
            'dates' => 'required|array|min:1|max:31',
            'dates.*' => 'date_format:Y-m-d',
            'source' => 'required|string|in:leave,wfh,hr,self,link,recurring',
            'reason' => 'nullable|string|max:255',
        ]);

        try {
            $summary = $action->execute(
                $company,
                $validated['employee_ids'],
                $validated['dates'],
                $validated['source'],
                $validated['reason'],
                Auth::user()
            );
        } catch (Exception $e) {
            return back()->withErrors(['bulk_skip' => $e->getMessage()]);
        }

        return back()->with('bulkSummary', $summary)->with('message', 'Bulk skips processed successfully.');
    }
}
