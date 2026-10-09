<?php

namespace App\Http\Controllers\CompanyAdmin;

use App\Actions\Company\BuildAttendanceShadowReport;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class AttendanceReportController extends Controller
{
    /**
     * What attendance would have changed, had it been allowed to.
     *
     * Read-only by construction: the action it calls only queries. The screen
     * states plainly that no count was affected, because a report full of
     * absences is otherwise easy to mistake for a report of meals already
     * withheld.
     */
    public function index(Request $request, BuildAttendanceShadowReport $action)
    {
        $user = Auth::user();
        $company = $user->company;

        if (! $company) {
            abort(403, 'Company context required.');
        }

        $range = (int) $request->query('range', 14);

        if (! in_array($range, [7, 14, 30], true)) {
            $range = 14;
        }

        $timezone = $company->setting?->timezone ?? config('mealbells.default_timezone', 'Asia/Kolkata');
        $today = Carbon::today($timezone);

        return Inertia::render('CompanyAdmin/Reports/Attendance', [
            'range' => $range,
            'report' => $action->execute(
                $company,
                $today->copy()->subDays($range - 1)->toDateString(),
                $today->toDateString(),
            ),
        ]);
    }
}
