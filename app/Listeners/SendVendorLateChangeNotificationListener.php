<?php

namespace App\Listeners;

use App\Events\PostCutoffChangeRecorded;
use App\Models\User;
use App\Notifications\VendorLateChangeNotification;

class SendVendorLateChangeNotificationListener
{
    public function handle(PostCutoffChangeRecorded $event): void
    {
        $change = $event->change;
        $mealCount = $change->mealCount;

        if (! $mealCount) {
            return;
        }

        $company = $mealCount->company;
        $tiffinService = $mealCount->tiffinService;

        if (! $tiffinService) {
            return;
        }

        $vendorUsers = User::where('tiffin_service_id', $tiffinService->id)
            ->where('role', 'tiffin_admin')
            ->get();

        foreach ($vendorUsers as $user) {
            $user->notify(new VendorLateChangeNotification(
                $company?->name ?? 'Company',
                $mealCount->date,
                $change->change_quantity,
                $change->reason
            ));
        }
    }
}
