<?php

namespace Tests\Feature;

use App\Actions\Meal\ConfirmDailyCount;
use App\Events\DailyCountConfirmed;
use App\Events\DailyMealOverridden;
use App\Events\PostCutoffChangeRecorded;
use App\Events\WeeklyMenuPublished;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CompanyTiffinAssignment;
use App\Models\Employee;
use App\Models\TiffinService;
use App\Models\User;
use App\Notifications\VendorCountReadyNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Each listener bound exactly once.
 *
 * It was bound twice: the framework discovers listeners in app/Listeners by
 * default, and AppServiceProvider registers all four explicitly. So every
 * notification they send went out twice - the vendor got two identical "your
 * count is ready" emails for every locked day, and two in-app copies, and the
 * same for late changes, published menus and daily overrides.
 *
 * Nothing caught it because assertSentTo() does not count. Only
 * assertSentToTimes() does, which is what this file uses.
 */
class ListenerRegistrationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array<int, string>> */
    public static function events(): array
    {
        return [
            'daily count confirmed' => [DailyCountConfirmed::class],
            'post-cutoff change' => [PostCutoffChangeRecorded::class],
            'weekly menu published' => [WeeklyMenuPublished::class],
            'daily meal overridden' => [DailyMealOverridden::class],
        ];
    }

    #[DataProvider('events')]
    public function test_the_event_has_exactly_one_listener(string $event): void
    {
        $listeners = Event::getRawListeners()[$event] ?? [];

        $this->assertCount(
            1,
            $listeners,
            $event.' is bound '.count($listeners).' times: '.json_encode($listeners),
        );
    }

    /**
     * The end-to-end version, which is what the duplicate actually looked like
     * to a vendor: two emails, same number, same minute.
     */
    public function test_locking_a_day_notifies_the_vendor_exactly_once(): void
    {
        Notification::fake();

        $tiffin = TiffinService::create([
            'name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890',
        ]);

        $vendor = User::create([
            'name' => 'Vendor', 'email' => 'vendor@royal.test', 'password' => bcrypt('password'),
            'role' => 'tiffin_admin', 'tiffin_service_id' => $tiffin->id,
        ]);

        $company = Company::create(['name' => 'Acme Industries', 'code' => 'ACME01']);

        CompanySetting::create([
            'company_id' => $company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);

        CompanyTiffinAssignment::create([
            'company_id' => $company->id, 'tiffin_service_id' => $tiffin->id,
            'is_active' => true, 'assigned_at' => '2026-09-01',
        ]);

        $admin = User::create([
            'name' => 'Acme HR', 'email' => 'hr@acme.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $company->id,
        ]);

        Employee::create([
            'company_id' => $company->id, 'employee_code' => 'ACME001',
            'name' => 'Alice', 'status' => 'active', 'is_meal_eligible' => true,
        ]);

        (new ConfirmDailyCount)->execute($company, '2026-10-05', $admin);

        Notification::assertSentToTimes($vendor, VendorCountReadyNotification::class, 1);
    }

    /**
     * The registrations stay greppable. Discovery would hide them: nothing in
     * the codebase would say what listens to what.
     */
    public function test_listener_discovery_is_off_and_registration_is_explicit(): void
    {
        $bootstrap = file_get_contents(base_path('bootstrap/app.php'));
        $provider = file_get_contents(app_path('Providers/AppServiceProvider.php'));

        $this->assertStringContainsString('withEvents(discover: false)', $bootstrap);

        foreach ([
            'SendVendorCountReadyNotificationListener',
            'SendVendorLateChangeNotificationListener',
            'SendWeeklyMenuPublishedNotification',
            'SendDailyMealOverrideNotification',
        ] as $listener) {
            $this->assertStringContainsString($listener, $provider, $listener.' is not registered explicitly');
        }
    }
}
