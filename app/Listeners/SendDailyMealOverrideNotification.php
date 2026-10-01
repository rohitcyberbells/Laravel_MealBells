<?php

namespace App\Listeners;

use App\Events\DailyMealOverridden;
use App\Models\CompanyTiffinAssignment;
use App\Models\User;
use App\Notifications\DailyMealOverrideNotification;
use Illuminate\Support\Facades\Notification;

class SendDailyMealOverrideNotification
{
    /**
     * Handle the event.
     */
    public function handle(DailyMealOverridden $event): void
    {
        $tiffinId = $event->override->tiffin_service_id;

        // Get company IDs assigned to this tiffin service
        $companyIds = CompanyTiffinAssignment::where('tiffin_service_id', $tiffinId)
            ->where('is_active', true)
            ->pluck('company_id');

        if ($companyIds->isEmpty()) {
            return;
        }

        // Get company admin users for those companies
        $companyAdmins = User::whereIn('company_id', $companyIds)
            ->where('role', 'company_admin')
            ->get();

        if ($companyAdmins->isNotEmpty()) {
            Notification::send($companyAdmins, new DailyMealOverrideNotification($event->override));
        }
    }
}
