<?php

namespace App\Listeners;

use App\Events\DailyCountConfirmed;
use App\Models\User;
use App\Notifications\VendorCountReadyNotification;

class SendVendorCountReadyNotificationListener
{
    public function handle(DailyCountConfirmed $event): void
    {
        $mealCount = $event->mealCount;
        $company = $mealCount->company;
        $tiffinService = $mealCount->tiffinService;

        if (! $tiffinService) {
            return;
        }

        $vendorUsers = User::where('tiffin_service_id', $tiffinService->id)
            ->where('role', 'tiffin_admin')
            ->get();

        foreach ($vendorUsers as $user) {
            $user->notify(new VendorCountReadyNotification(
                $company?->name ?? 'Company',
                $mealCount->date,
                $mealCount->adjusted_total,
                // So the mail can carry every company this vendor cooks for on
                // this date. They cook for all of them at once, so one
                // company's number alone is not the kitchen's workload.
                $tiffinService->id,
            ));
        }
    }
}
