<?php

namespace Tests\Feature;

use App\Actions\Meal\ConfirmDailyCount;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CompanyTiffinAssignment;
use App\Models\Employee;
use App\Models\MealCount;
use App\Models\TiffinService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SnapshotLockTypeTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected TiffinService $tiffin;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tiffin = TiffinService::create(['name' => 'Annapurna Tiffin']);
        $this->company = Company::create(['name' => 'Acme Industries', 'code' => 'ACME01']);

        CompanySetting::create([
            'company_id' => $this->company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);

        CompanyTiffinAssignment::create([
            'company_id' => $this->company->id, 'tiffin_service_id' => $this->tiffin->id,
            'is_active' => true, 'assigned_at' => '2026-09-01',
        ]);

        $this->admin = User::create([
            'name' => 'Acme HR', 'email' => 'hr@acme.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->company->id,
        ]);

        Employee::create([
            'company_id' => $this->company->id, 'employee_code' => 'ACME001',
            'name' => 'Alice', 'status' => 'active', 'is_meal_eligible' => true,
        ]);
    }

    public function test_a_cutoff_lock_is_recorded_as_auto(): void
    {
        $snapshot = (new ConfirmDailyCount)->execute($this->company, '2026-10-05', null, true);

        $this->assertEquals('auto', $snapshot->lock_type);
        $this->assertEquals('auto_confirmed', $snapshot->status);
        $this->assertNotNull($snapshot->locked_at);
    }

    public function test_a_person_triggered_lock_is_recorded_as_manual(): void
    {
        $snapshot = (new ConfirmDailyCount)->execute($this->company, '2026-10-05', $this->admin, false);

        $this->assertEquals('manual', $snapshot->lock_type);
        $this->assertEquals('confirmed', $snapshot->status);
        $this->assertEquals($this->admin->id, $snapshot->confirmed_by);
    }

    public function test_the_scheduled_command_leaves_an_auto_lock(): void
    {
        // Past the 11:00 cutoff, so process-cutoff locks today.
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', 'Asia/Kolkata'));

        $this->artisan('mealbells:process-cutoff')->assertExitCode(0);

        $snapshot = MealCount::where('company_id', $this->company->id)->where('date', '2026-10-05')->sole();

        $this->assertEquals('auto', $snapshot->lock_type);
    }

    public function test_the_daily_page_shows_the_lock_type(): void
    {
        (new ConfirmDailyCount)->execute($this->company, '2026-10-05', null, true);

        $props = $this->actingAs($this->admin)->get('/company-admin/daily?date=2026-10-05')
            ->getOriginalContent()->getData()['page']['props'];

        $this->assertEquals('locked', $props['status']);
        $this->assertEquals('auto', $props['snapshot']['lock_type']);
    }

    public function test_a_snapshot_locked_before_this_change_still_renders(): void
    {
        // Rows locked by the old code carry a null lock_type; the page must not
        // break on them, and the template only shows the badge when it is set.
        MealCount::create([
            'company_id' => $this->company->id,
            'tiffin_service_id' => $this->tiffin->id,
            'date' => '2026-10-05',
            'base_eligible_count' => 1, 'skip_count' => 0, 'extra_count' => 0,
            'final_expected_count' => 1, 'breakdown' => [],
            'status' => 'auto_confirmed', 'lock_type' => null, 'locked_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get('/company-admin/daily?date=2026-10-05');
        $response->assertStatus(200);

        $props = $response->getOriginalContent()->getData()['page']['props'];

        $this->assertEquals('locked', $props['status']);
        $this->assertNull($props['snapshot']['lock_type']);

        $daily = file_get_contents(resource_path('js/Pages/CompanyAdmin/Daily/Index.vue'));
        $this->assertStringContainsString('v-if="snapshot?.lock_type"', $daily);
    }

    public function test_relocking_an_already_locked_day_does_not_change_the_lock_type(): void
    {
        $first = (new ConfirmDailyCount)->execute($this->company, '2026-10-05', null, true);

        // Idempotent: the existing locked snapshot is returned untouched, so a
        // later manual call cannot relabel how the day was locked.
        $second = (new ConfirmDailyCount)->execute($this->company, '2026-10-05', $this->admin, false);

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals('auto', $second->fresh()->lock_type);
        $this->assertEquals(1, MealCount::count());
    }
}
