<?php

namespace App\Http\Controllers\CompanyAdmin;

use App\Actions\Meal\CancelExtraMeal;
use App\Actions\Meal\RecordExtraMeal;
use App\Http\Controllers\Controller;
use App\Models\MealAdjustment;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MealAdjustmentController extends Controller
{
    public function store(Request $request, RecordExtraMeal $action)
    {
        $company = Auth::user()->company;

        $validated = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'quantity' => 'required|integer|min:1|max:100',
            'type' => 'required|string|in:guest,visitor,other',
            'reason' => 'nullable|string|max:255',
        ]);

        try {
            $action->execute(
                $company,
                Auth::user(),
                $validated['date'],
                $validated['quantity'],
                $validated['type'],
                $validated['reason']
            );
        } catch (Exception $e) {
            return back()->withErrors(['extra_meal' => $e->getMessage()]);
        }

        return back()->with('message', 'Extra meals recorded successfully.');
    }

    public function destroy(MealAdjustment $adjustment, CancelExtraMeal $action)
    {
        $company = Auth::user()->company;

        try {
            $action->execute($company, $adjustment, Auth::user());
        } catch (Exception $e) {
            return back()->withErrors(['extra_meal' => $e->getMessage()]);
        }

        return back()->with('message', 'Extra meal adjustment cancelled successfully.');
    }
}
