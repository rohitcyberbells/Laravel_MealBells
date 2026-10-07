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
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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

        /*
         * One definition of an acceptable password, for every place one is set.
         *
         * It was min:8 for a forced change and min:6 for creating an admin and
         * for the HRMS pull credential, so the weakest rule governed the most
         * privileged account.
         *
         * 'uncompromised' is left off deliberately: it calls out to the Have I
         * Been Pwned API, and a cutoff that depends on a third party being
         * reachable is a worse failure than a weak password.
         */
        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        /*
         * The HRMS webhook is a public internet endpoint, so it is limited per
         * company rather than per IP: a vendor's whole fleet shares one budget.
         */
        RateLimiter::for('hrms-webhook', fn (Request $request) => Limit::perMinute(
            (int) config('hrms.rate_limit_per_minute', 300)
        )->by($request->route('company')?->id ?? $request->ip()));

        /*
         * Sign-in, limited two ways at once.
         *
         * Per IP alone is the wrong shape on its own: a whole office behind one
         * address shares the budget, so five fat-fingered attempts lock out
         * everyone, while an attacker spraying one password across many
         * accounts from many addresses never trips it.
         *
         * So the account gets its own slower budget as well. A request has to
         * satisfy both.
         */
        /*
         * The reset form answers identically whether or not an account exists,
         * so without a limit it is a way to mail-bomb one address, or to walk a
         * list of them. Limited per address and per place at once.
         */
        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perMinute(10)->by('ip:'.$request->ip()),
            Limit::perHour(5)->by('email:'.strtolower(trim((string) $request->input('email')))),
        ]);

        RateLimiter::for('login', function (Request $request) {
            $identifier = strtolower(trim((string) (
                $request->input('identifier')
                ?? $request->input('email')
                ?? $request->input('login_code')
                ?? ''
            )));

            // Scoped by company too, because an employee code is only unique
            // within one.
            $account = $identifier.'|'.strtolower(trim((string) $request->input('company_code')));

            return [
                Limit::perMinute(20)->by('ip:'.$request->ip()),
                $identifier === ''
                    ? Limit::perMinute(20)->by('ip:'.$request->ip())
                    : Limit::perMinute(5)->by('account:'.$account),
            ];
        });
    }
}
