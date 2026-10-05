<?php

namespace App\Providers;

use App\Events\DailyCountConfirmed;
use App\Events\DailyMealOverridden;
use App\Events\PostCutoffChangeRecorded;
use App\Events\WeeklyMenuPublished;
use App\Listeners\SendDailyMealOverrideNotification;
use App\Listeners\SendVendorCountReadyNotificationListener;
use App\Listeners\SendVendorLateChangeNotificationListener;
use App\Listeners\SendWeeklyMenuPublishedNotification;
use App\Models\Employee;
use App\Observers\EmployeeObserver;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(
            WeeklyMenuPublished::class,
            SendWeeklyMenuPublishedNotification::class
        );

        Event::listen(
            DailyMealOverridden::class,
            SendDailyMealOverrideNotification::class
        );

        Event::listen(
            DailyCountConfirmed::class,
            SendVendorCountReadyNotificationListener::class
        );

        Event::listen(
            PostCutoffChangeRecorded::class,
            SendVendorLateChangeNotificationListener::class
        );

        Employee::observe(EmployeeObserver::class);
    }
}
