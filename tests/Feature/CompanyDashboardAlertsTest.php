<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\User;
use App\Notifications\AnomalyEscalationNotification;
use App\Notifications\AttentionTypes;
use App\Notifications\DailyCountSummaryNotification;
use App\Notifications\DailyMealOverrideNotification;
use App\Notifications\EmployeeLoginCreatedNotification;
use App\Notifications\HealthIssueNotification;
use App\Notifications\HealthResolvedNotification;
use App\Notifications\NeedsAttention;
use App\Notifications\PasswordResetLinkNotification;
use App\Notifications\SuperAdminCutoffErrorNotification;
use App\Notifications\VendorCountReadyNotification;
use App\Notifications\VendorLateChangeNotification;
use App\Notifications\WeeklyMenuPublishedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The dashboard's alerts panel carries only what someone has to act on.
 *
 * It used to carry every notification the account had, which in practice meant
 * four routine daily summaries - the same count the page already prints in
 * large type further down. A panel that is always full of nothing is one people
 * stop reading, and the escalation that matters then arrives looking identical
 * to the noise.
 */
class CompanyDashboardAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inertia.ssr.enabled', false);

        $company = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);

        CompanySetting::create([
            'company_id' => $company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);

        $this->admin = User::create([
            'name' => 'Alpha HR', 'email' => 'hr@alpha.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $company->id,
        ]);
    }

    protected function notify(string $type, string $title): void
    {
        $this->admin->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => $type,
            'data' => ['title' => $title, 'message' => 'body'],
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    protected function panel(): array
    {
        return $this->actingAs($this->admin)->get('/company-admin/dashboard')
            ->getOriginalContent()->getData()['page']['props']['notifications'];
    }

    public function test_a_routine_summary_stays_out_of_the_panel(): void
    {
        $this->notify(DailyCountSummaryNotification::class, 'Daily Meal Count Summary');
        $this->notify(VendorCountReadyNotification::class, 'Count ready');
        $this->notify(WeeklyMenuPublishedNotification::class, 'Menu published');

        $this->assertSame([], $this->panel());
    }

    public function test_an_escalation_reaches_the_panel(): void
    {
        $this->notify(AnomalyEscalationNotification::class, 'Unusual count');

        $titles = array_column(array_column($this->panel(), 'data'), 'title');

        $this->assertSame(['Unusual count'], $titles);
    }

    /**
     * The one failure that would be silent: an alert sent to an account that
     * never shows up, because the routine ones crowded the query.
     */
    public function test_an_escalation_is_not_buried_by_routine_notifications(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->notify(DailyCountSummaryNotification::class, "Summary {$i}");
        }

        $this->notify(AnomalyEscalationNotification::class, 'Unusual count');

        // Written first, so it sits behind thirty summaries by insertion order.
        $this->admin->notifications()
            ->where('type', AnomalyEscalationNotification::class)
            ->update(['created_at' => now()->subDay()]);

        $titles = array_column(array_column($this->panel(), 'data'), 'title');

        $this->assertSame(['Unusual count'], $titles);
    }

    public function test_the_marked_notifications_are_the_ones_resolved(): void
    {
        AttentionTypes::forget();

        $expected = [
            AnomalyEscalationNotification::class,
            DailyMealOverrideNotification::class,
            HealthIssueNotification::class,
            HealthResolvedNotification::class,
            SuperAdminCutoffErrorNotification::class,
            VendorLateChangeNotification::class,
        ];

        $this->assertSame($expected, AttentionTypes::all());

        // The routine ones must stay unmarked, or the panel fills up again.
        foreach ([
            DailyCountSummaryNotification::class,
            VendorCountReadyNotification::class,
            WeeklyMenuPublishedNotification::class,
            EmployeeLoginCreatedNotification::class,
            PasswordResetLinkNotification::class,
        ] as $routine) {
            $this->assertNotInstanceOf(NeedsAttention::class, new \ReflectionClass($routine)->newInstanceWithoutConstructor());
        }
    }

    public function test_the_panel_is_not_rendered_when_there_is_nothing_to_act_on(): void
    {
        $this->notify(DailyCountSummaryNotification::class, 'Daily Meal Count Summary');

        $this->actingAs($this->admin)->get('/company-admin/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->component('CompanyAdmin/Dashboard')
                ->has('notifications', 0)
                ->etc()
            );
    }
}
