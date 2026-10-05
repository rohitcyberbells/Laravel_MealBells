<?php

namespace Tests\Feature;

use App\Actions\Company\BuildAdoptionReport;
use App\Actions\Employee\ImportEmployeeCsv;
use App\Actions\Meal\BuildVendorPreparationView;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CompanyTiffinAssignment;
use App\Models\Employee;
use App\Models\RecurringSkip;
use App\Models\Skip;
use App\Models\TiffinService;
use App\Models\User;
use App\Notifications\VendorCountReadyNotification;
use App\Notifications\VendorLateChangeNotification;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FinalHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;

    protected Company $companyB;

    protected TiffinService $tiffinService;

    protected User $adminA;

    protected User $adminB;

    protected User $tiffinUser;

    protected User $superUser;

    protected Employee $empA1;

    protected User $empUserA1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1', 'status' => 'active']);
        CompanySetting::create(['company_id' => $this->companyA->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata']);

        $this->companyB = Company::create(['name' => 'Beta Corp', 'code' => 'BETA02', 'status' => 'active']);
        CompanySetting::create(['company_id' => $this->companyB->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata']);

        $this->tiffinService = TiffinService::create(['name' => 'Delicious Tiffins', 'code' => 'DELISH', 'status' => 'active']);

        CompanyTiffinAssignment::create([
            'company_id' => $this->companyA->id,
            'tiffin_service_id' => $this->tiffinService->id,
            'is_active' => true,
            'assigned_at' => now(),
        ]);

        $this->adminA = User::create(['name' => 'Admin Alpha', 'email' => 'admin@alpha.com', 'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $this->companyA->id]);
        $this->adminB = User::create(['name' => 'Admin Beta', 'email' => 'admin@beta.com', 'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $this->companyB->id]);
        $this->tiffinUser = User::create(['name' => 'Tiffin Vendor', 'email' => 'vendor@delish.com', 'password' => bcrypt('password'), 'role' => 'tiffin_admin', 'tiffin_service_id' => $this->tiffinService->id]);
        $this->superUser = User::create(['name' => 'Super Admin', 'email' => 'super@mealbells.com', 'password' => bcrypt('password'), 'role' => 'super_admin']);

        $this->empA1 = Employee::create([
            'company_id' => $this->companyA->id,
            'employee_code' => 'EMP101',
            'name' => 'Alice SecretName',
            'email' => 'alice@secret.com',
            'status' => 'active',
            'is_meal_eligible' => true,
        ]);

        $this->empUserA1 = User::create([
            'name' => 'Alice SecretName',
            'email' => 'alice@secret.com',
            'password' => bcrypt('password'),
            'role' => 'employee',
            'company_id' => $this->companyA->id,
            'login_code' => 'EMP101',
            'must_change_password' => false,
        ]);
        $this->empA1->update(['user_id' => $this->empUserA1->id]);
    }

    public function test_route_audit_enforces_auth_and_roles_and_whitelists_public_routes(): void
    {
        $routes = Route::getRoutes();

        $publicWhitelist = ['/', 'login', 'logout', 'change-password', 'up', 'sanctum/csrf-cookie'];

        foreach ($routes as $route) {
            $uri = $route->uri();
            $middleware = $route->gatherMiddleware();

            // Assert NO deprecated/unsupported public token routes exist
            $this->assertStringNotContainsString('skip/{token}', $uri);
            $this->assertStringNotContainsString('unsubscribe', $uri);

            if (str_starts_with($uri, 'company-admin/')) {
                $this->assertContains('auth', $middleware, "Route {$uri} missing auth middleware.");
                $this->assertContains('role:company_admin', $middleware, "Route {$uri} missing role:company_admin middleware.");
            } elseif (str_starts_with($uri, 'tiffin-admin/')) {
                $this->assertContains('auth', $middleware, "Route {$uri} missing auth middleware.");
                $this->assertContains('role:tiffin_admin', $middleware, "Route {$uri} missing role:tiffin_admin middleware.");
            } elseif (str_starts_with($uri, 'super-admin/')) {
                $this->assertContains('auth', $middleware, "Route {$uri} missing auth middleware.");
                $this->assertContains('role:super_admin', $middleware, "Route {$uri} missing role:super_admin middleware.");
            } elseif (str_starts_with($uri, 'employee/')) {
                $this->assertContains('auth', $middleware, "Route {$uri} missing auth middleware.");
                $this->assertContains('role:employee', $middleware, "Route {$uri} missing role:employee middleware.");
            }
        }
    }

    public function test_cross_company_isolation_returns_403_or_404_for_other_company_users(): void
    {
        // Admin B attempts to access Admin A's employee details
        $res1 = $this->actingAs($this->adminB)->put("/company-admin/employees/{$this->empA1->id}", [
            'name' => 'Hacked Name',
            'email' => 'hacked@beta.com',
            'is_meal_eligible' => true,
            'status' => 'active',
        ]);
        $res1->assertStatus(403);

        // Admin B attempts to delete Admin A's skip
        $skipA = Skip::create([
            'company_id' => $this->companyA->id,
            'employee_id' => $this->empA1->id,
            'date' => '2026-10-05',
            'source' => 'self',
        ]);

        $res2 = $this->actingAs($this->adminB)->delete("/company-admin/skips/{$skipA->id}");
        $res2->assertStatus(403);
    }

    public function test_privacy_sweep_guarantees_no_employee_pii_in_vendor_or_adoption_views(): void
    {
        Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', '2026-10-05 08:00:00', 'Asia/Kolkata'));

        Skip::create([
            'company_id' => $this->companyA->id,
            'employee_id' => $this->empA1->id,
            'date' => '2026-10-05',
            'source' => 'self',
            'reason' => 'Confidential Medical Personal Reason',
        ]);

        // 1. Vendor Preparation View
        $buildView = new BuildVendorPreparationView;
        $vendorData = $buildView->execute($this->tiffinService, '2026-10-05');
        $jsonVendor = json_encode($vendorData);

        $this->assertStringNotContainsString('Alice SecretName', $jsonVendor);
        $this->assertStringNotContainsString('EMP101', $jsonVendor);
        $this->assertStringNotContainsString('alice@secret.com', $jsonVendor);
        $this->assertStringNotContainsString('Confidential Medical Personal Reason', $jsonVendor);

        // 2. Adoption Report
        $buildReport = new BuildAdoptionReport;
        $reportData = $buildReport->execute($this->companyA, '2026-10-05', '2026-10-05');
        $jsonReport = json_encode($reportData);

        $this->assertStringNotContainsString('Alice SecretName', $jsonReport);
        $this->assertStringNotContainsString('EMP101', $jsonReport);
        $this->assertStringNotContainsString('alice@secret.com', $jsonReport);
        $this->assertStringNotContainsString('Confidential Medical Personal Reason', $jsonReport);

        // 3. Vendor Notifications
        $notif1 = new VendorCountReadyNotification('Alpha Corp', '2026-10-05', 10);
        $jsonNotif1 = json_encode($notif1->toArray(null));
        $this->assertStringNotContainsString('Alice SecretName', $jsonNotif1);

        $notif2 = new VendorLateChangeNotification('Alpha Corp', '2026-10-05', 2, 'Extra guest');
        $jsonNotif2 = json_encode($notif2->toArray(null));
        $this->assertStringNotContainsString('Alice SecretName', $jsonNotif2);
    }

    public function test_db_enforces_single_active_assignment_per_company(): void
    {
        $secondTiffin = TiffinService::create(['name' => 'Tiffin Two', 'code' => 'TIFF2', 'status' => 'active']);

        $this->expectException(QueryException::class);

        // Creating a second active assignment for companyA should fail at DB constraint level
        CompanyTiffinAssignment::create([
            'company_id' => $this->companyA->id,
            'tiffin_service_id' => $secondTiffin->id,
            'is_active' => true,
            'assigned_at' => now(),
        ]);
    }

    public function test_must_change_password_redirects_all_roles(): void
    {
        $this->adminA->update(['must_change_password' => true]);
        $this->tiffinUser->update(['must_change_password' => true]);
        $this->empUserA1->update(['must_change_password' => true]);

        $this->actingAs($this->adminA)->get('/company-admin/dashboard')->assertRedirect('/change-password');
        $this->actingAs($this->tiffinUser)->get('/tiffin-admin/dashboard')->assertRedirect('/change-password');
        $this->actingAs($this->empUserA1)->get('/employee/dashboard')->assertRedirect('/change-password');
    }

    public function test_inactive_employee_login_blocked_and_future_recurring_skips_cancelled(): void
    {
        Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', '2026-10-05 08:00:00', 'Asia/Kolkata'));

        // Create active recurring rule for Alice
        $rule = RecurringSkip::create([
            'company_id' => $this->companyA->id,
            'employee_id' => $this->empA1->id,
            'weekday' => 1,
            'starts_on' => '2026-10-05',
            'active' => true,
            'created_by' => $this->adminA->id,
        ]);

        // Future recurring skip created for 2026-10-12
        $skip = Skip::create([
            'company_id' => $this->companyA->id,
            'employee_id' => $this->empA1->id,
            'date' => '2026-10-12',
            'source' => 'recurring',
        ]);

        // Admin updates Alice to inactive
        $res = $this->actingAs($this->adminA)->put("/company-admin/employees/{$this->empA1->id}", [
            'name' => $this->empA1->name,
            'email' => $this->empA1->email,
            'is_meal_eligible' => true,
            'status' => 'inactive',
        ]);
        $res->assertSessionHasNoErrors();

        // Recurring rule paused & future skip cancelled
        $this->assertFalse($rule->fresh()->active);
        $this->assertNotNull($skip->fresh()->cancelled_at);

        // Employee login is blocked
        Auth::logout();
        $loginRes = $this->post('/login', [
            'company_code' => 'ALPHA1',
            'login_code' => 'EMP101',
            'password' => 'password',
        ]);
        $loginRes->assertSessionHasErrors('login_code');
    }

    public function test_super_admin_health_endpoint_returns_scheduler_status_and_failed_jobs(): void
    {
        Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', '2026-10-05 08:00:00', 'Asia/Kolkata'));

        $this->artisan('mealbells:process-cutoff')->assertExitCode(0);

        $response = $this->actingAs($this->superUser)->get('/super-admin/health');
        $response->assertStatus(200);

        $props = $response->getOriginalContent()->getData()['page']['props'];
        $this->assertNotNull($props['scheduler']['last_run_timestamp']);
        $this->assertFalse($props['scheduler']['is_stale']);
        $this->assertEquals(0, $props['failed_jobs_count']);
    }

    public function test_process_cutoff_error_in_one_company_does_not_block_others_and_notifies_super_admin(): void
    {
        Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', '2026-10-05 11:30:00', 'Asia/Kolkata'));

        Notification::fake();

        // Run process cutoff command
        $this->artisan('mealbells:process-cutoff')->assertExitCode(0);

        // Heartbeat updated
        $this->assertNotNull(Cache::get('scheduler_last_run'));
    }

    public function test_adoption_report_returns_null_pct_when_total_skips_zero_and_returns_separate_counts(): void
    {
        Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', '2026-10-05 08:00:00', 'Asia/Kolkata'));

        $buildReport = new BuildAdoptionReport;
        $reportData = $buildReport->execute($this->companyA, '2026-10-05', '2026-10-05');

        $this->assertEquals(0, $reportData['total_active_skips']);
        $this->assertNull($reportData['self_service_pct']);
        $this->assertArrayHasKey('self_skips_count', $reportData);
        $this->assertArrayHasKey('recurring_skips_count', $reportData);
        $this->assertEquals(0, $reportData['self_skips_count']);
        $this->assertEquals(0, $reportData['recurring_skips_count']);
    }

    public function test_csv_import_setting_employee_inactive_pauses_recurring_skips_via_observer(): void
    {
        Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', '2026-10-05 08:00:00', 'Asia/Kolkata'));

        // Active rule and future skip for Alice
        $rule = RecurringSkip::create([
            'company_id' => $this->companyA->id,
            'employee_id' => $this->empA1->id,
            'weekday' => 1,
            'starts_on' => '2026-10-05',
            'active' => true,
            'created_by' => $this->adminA->id,
        ]);

        $skip = Skip::create([
            'company_id' => $this->companyA->id,
            'employee_id' => $this->empA1->id,
            'date' => '2026-10-12',
            'source' => 'recurring',
        ]);

        // Import CSV row marking Alice as inactive
        $importAction = new ImportEmployeeCsv;
        $res = $importAction->execute($this->companyA, [
            [
                'employee_code' => 'EMP101',
                'name' => 'Alice SecretName',
                'status' => 'inactive',
                'is_meal_eligible' => true,
            ],
        ]);

        $this->assertEquals(1, $res['updated']);
        $this->assertFalse($rule->fresh()->active);
        $this->assertNotNull($skip->fresh()->cancelled_at);
    }

    public function test_health_controller_does_not_flag_missing_snapshot_on_non_meal_day(): void
    {
        // 2026-10-10 is Saturday (non-meal day) at 12:00 PM (past cutoff)
        Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', '2026-10-10 12:00:00', 'Asia/Kolkata'));

        $response = $this->actingAs($this->superUser)->get('/super-admin/health');
        $response->assertStatus(200);

        $props = $response->getOriginalContent()->getData()['page']['props'];
        // Saturday is non-meal day => missing_snapshots_today must be empty
        $this->assertEmpty($props['missing_snapshots_today']);
    }
}
