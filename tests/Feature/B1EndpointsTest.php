<?php

namespace Tests\Feature;

use App\Actions\Meal\ConfirmDailyCount;
use App\Events\DailyCountConfirmed;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CompanyTiffinAssignment;
use App\Models\Employee;
use App\Models\MealCount;
use App\Models\TiffinService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class B1EndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;

    protected Company $companyB;

    protected User $adminA;

    protected User $adminB;

    protected User $tiffinUser;

    protected TiffinService $tiffinService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = Company::create(['name' => 'Company Alpha']);
        $this->companyB = Company::create(['name' => 'Company Beta']);

        CompanySetting::create([
            'company_id' => $this->companyA->id,
            'cutoff_time' => '11:00:00',
            'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => false,
            'meal_days' => [1, 2, 3, 4, 5],
        ]);

        CompanySetting::create([
            'company_id' => $this->companyB->id,
            'cutoff_time' => '11:00:00',
            'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => false,
            'meal_days' => [1, 2, 3, 4, 5],
        ]);

        $this->tiffinService = TiffinService::create([
            'name' => 'Royal Tiffin',
            'email' => 'vendor@tiffin.com',
            'phone' => '9998887770',
        ]);

        CompanyTiffinAssignment::create([
            'company_id' => $this->companyA->id,
            'tiffin_service_id' => $this->tiffinService->id,
            'is_active' => true,
        ]);

        $this->adminA = User::create([
            'name' => 'Alice Admin',
            'email' => 'alice@company-a.com',
            'password' => bcrypt('password'),
            'role' => 'company_admin',
            'company_id' => $this->companyA->id,
        ]);

        $this->adminB = User::create([
            'name' => 'Bob Admin',
            'email' => 'bob@company-b.com',
            'password' => bcrypt('password'),
            'role' => 'company_admin',
            'company_id' => $this->companyB->id,
        ]);

        $this->tiffinUser = User::create([
            'name' => 'Vendor User',
            'email' => 'vendor@tiffin.com',
            'password' => bcrypt('password'),
            'role' => 'tiffin_admin',
            'tiffin_service_id' => $this->tiffinService->id,
        ]);
    }

    public function test_get_daily_endpoint_returns_expected_inertia_props(): void
    {
        Employee::create([
            'company_id' => $this->companyA->id,
            'employee_code' => 'EMP1',
            'name' => 'Emp One',
            'email' => 'emp1@a.com',
            'status' => 'active',
            'is_meal_eligible' => true,
        ]);

        $response = $this->actingAs($this->adminA)->get('/company-admin/daily?date=2026-10-05');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('CompanyAdmin/Daily/Index')
            ->where('date', '2026-10-05')
            ->has('count')
            ->has('status')
            ->has('cutoff_time')
            ->has('seconds_left')
            ->has('skips')
            ->has('extra_meals')
            ->has('employees_for_search')
        );
    }

    public function test_get_daily_returns_locked_status_when_snapshot_is_locked(): void
    {
        MealCount::create([
            'company_id' => $this->companyA->id,
            'tiffin_service_id' => $this->tiffinService->id,
            'date' => '2026-10-05',
            'base_eligible_count' => 10,
            'skip_count' => 2,
            'extra_count' => 1,
            'final_expected_count' => 9,
            'adjusted_total' => 9,
            'breakdown' => ['leave' => 0, 'wfh' => 0, 'hr' => 0, 'self' => 0, 'link' => 0, 'recurring' => 0],
            'status' => 'confirmed',
            'locked_at' => now(),
        ]);

        $response = $this->actingAs($this->adminA)->get('/company-admin/daily?date=2026-10-05');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('CompanyAdmin/Daily/Index')
            ->where('status', 'locked')
            ->where('count.adjusted_total', 9)
        );
    }

    public function test_get_settings_endpoint_returns_correct_props(): void
    {
        $response = $this->actingAs($this->adminA)->get('/company-admin/settings');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('CompanyAdmin/Settings/Index')
            ->where('cutoff_time', '11:00:00')
            ->where('timezone', 'Asia/Kolkata')
            ->has('company_admins')
        );
    }

    public function test_update_settings_validates_primary_and_backup_admin_ownership(): void
    {
        // Try assigning Bob (from Company B) as primary admin of Company A -> Should Fail
        $response = $this->actingAs($this->adminA)->put('/company-admin/settings', [
            'cutoff_time' => '11:30:00',
            'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => false,
            'meal_days' => [1, 2, 3, 4, 5],
            'primary_admin_id' => $this->adminB->id,
        ]);

        $response->assertSessionHasErrors('primary_admin_id');
    }

    public function test_post_late_changes_creates_change_and_returns_friendly_error_on_negative_total(): void
    {
        $snapshot = MealCount::create([
            'company_id' => $this->companyA->id,
            'tiffin_service_id' => $this->tiffinService->id,
            'date' => '2026-10-05',
            'base_eligible_count' => 2,
            'skip_count' => 0,
            'extra_count' => 0,
            'final_expected_count' => 2,
            'adjusted_total' => 2,
            'breakdown' => ['leave' => 0, 'wfh' => 0, 'hr' => 0, 'self' => 0, 'link' => 0, 'recurring' => 0],
            'status' => 'confirmed',
            'locked_at' => now(),
        ]);

        // Attempt change -5 on total of 2 -> Negative total violation
        $response = $this->actingAs($this->adminA)->post('/company-admin/late-changes', [
            'date' => '2026-10-05',
            'change_quantity' => -5,
            'reason' => 'Emergency cut',
        ]);

        $response->assertSessionHasErrors('late_change');
    }

    public function test_create_company_admin_action_and_endpoint(): void
    {
        $response = $this->actingAs($this->adminA)->post('/company-admin/admins', [
            'name' => 'New Admin',
            'email' => 'newadmin@company-a.com',
        ]);

        $response->assertSessionHas('temporary_password');
        $this->assertDatabaseHas('users', [
            'company_id' => $this->companyA->id,
            'email' => 'newadmin@company-a.com',
            'role' => 'company_admin',
            'must_change_password' => true,
        ]);
    }

    public function test_tiffin_admin_cannot_access_company_admin_routes(): void
    {
        $response = $this->actingAs($this->tiffinUser)->get('/company-admin/daily');
        $response->assertStatus(403);
    }

    public function test_confirm_daily_count_executes_idempotently_and_dispatches_single_event(): void
    {
        Event::fake([DailyCountConfirmed::class]);

        Employee::create([
            'company_id' => $this->companyA->id,
            'employee_code' => 'E100',
            'name' => 'Emp 100',
            'status' => 'active',
            'is_meal_eligible' => true,
        ]);

        $action = new ConfirmDailyCount;

        $snapshot1 = $action->execute($this->companyA, '2026-10-05', $this->adminA);
        $snapshot2 = $action->execute($this->companyA, '2026-10-05', $this->adminA);

        $this->assertEquals($snapshot1->id, $snapshot2->id);

        $this->assertDatabaseCount('meal_counts', 1);
        Event::assertDispatched(DailyCountConfirmed::class, 1);
    }
}
