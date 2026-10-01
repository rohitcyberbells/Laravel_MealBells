<?php

namespace App\Providers;

use App\Events\DailyMealOverridden;
use App\Events\WeeklyMenuPublished;
use App\Listeners\SendDailyMealOverrideNotification;
use App\Listeners\SendWeeklyMenuPublishedNotification;
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
    }
}
