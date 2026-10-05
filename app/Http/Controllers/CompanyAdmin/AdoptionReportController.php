<?php

namespace App\Http\Controllers\CompanyAdmin;

use App\Actions\Company\BuildAdoptionReport;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class AdoptionReportController extends Controller
{
    public function index(Request $request, BuildAdoptionReport $action)
    {
        $user = Auth::user();
        $company = $user->company;

        if (! $company) {
            abort(403, 'Company context required.');
        }

        $range = (int) $request->query('range', 7);
        if (! in_array($range, [7, 30], true)) {
            $range = 7;
        }

        $timezone = $company->setting?->timezone ?? 'Asia/Kolkata';
        $today = Carbon::today($timezone);
        $fromDate = $today->copy()->subDays($range - 1)->toDateString();
        $toDate = $today->toDateString();

        $report = $action->execute($company, $fromDate, $toDate);

        return Inertia::render('CompanyAdmin/Reports/Adoption', [
            'range' => $range,
            'report' => $report,
        ]);
    }
}
