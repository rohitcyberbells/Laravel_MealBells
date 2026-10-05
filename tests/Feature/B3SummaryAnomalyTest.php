<?php

namespace Tests\Feature;

use App\Actions\Meal\AcknowledgeDailyCount;
use App\Actions\Meal\ConfirmDailyCount;
use App\Actions\Meal\DetectCountAnomalies;
use App\Actions\Meal\EscalateUnreviewedAnomaly;
use App\Actions\Meal\PrepareDailyCountSummary;
use App\Actions\Meal\RecordPostCutoffChange;
use App\Exceptions\MealRuleViolation;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CompanyTiffinAssignment;
use App\Models\Employee;
use App\Models\MealCount;
use App\Models\TiffinService;
use App\Models\User;
use App\Notifications\AnomalyEscalationNotification;
use App\Notifications\DailyCountSummaryNotification;
use App\Notifications\VendorCountReadyNotification;
use App\Notifications\VendorLateChangeNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class B3SummaryAnomalyTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;

    protected Company $companyB;

    protected User $primaryAdminA;

    protected User $backupAdminA;

    protected User $tiffinUser;

    protected TiffinService $tiffinService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = Company::create(['name' => 'Company A']);
        $this->companyB = Company::create(['name' => 'Company B']);

        $this->tiffinService = TiffinService::create([
            'name' => 'Tasty Tiffin',
            'email' => 'tasty@tiffin.com',
            'phone' => '9876543210',
        ]);

        CompanyTiffinAssignment::create([
            'company_id' => $this->companyA->id,
            'tiffin_service_id' => $this->tiffinService->id,
            'is_active' => true,
        ]);

        $this->primaryAdminA = User::create([
            'name' => 'Primary Admin A',
            'email' => 'primary@company-a.com',
            'password' => bcrypt('password'),
            'role' => 'company_admin',
            'company_id' => $this->companyA->id,
        ]);

        $this->backupAdminA = User::create([
            'name' => 'Backup Admin A',
            'email' => 'backup@company-a.com',
            'password' => bcrypt('password'),
            'role' => 'company_admin',
            'company_id' => $this->companyA->id,
        ]);

        CompanySetting::create([
            'company_id' => $this->companyA->id,
            'cutoff_time' => '11:00:00',
            'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true,
            'meal_days' => [1, 2, 3, 4, 5],
            'primary_admin_id' => $this->primaryAdminA->id,
            'backup_admin_id' => $this->backupAdminA->id,
        ]);

        $this->tiffinUser = User::create([
            'name' => 'Vendor User',
            'email' => 'vendor@tasty.com',
            'password' => bcrypt('password'),
            'role' => 'tiffin_admin',
            'tiffin_service_id' => $this->tiffinService->id,
        ]);

        // Create 10 active eligible employees for Company A
        for ($i = 1; $i <= 10; $i++) {
            Employee::create([
                'company_id' => $this->companyA->id,
                'employee_code' => "EMP{$i}",
                'name' => "Employee {$i}",
                'status' => 'active',
                'is_meal_eligible' => true,
            ]);
        }
    }

    public function test_summary_sent_once_and_is_idempotent(): void
    {
        Notification::fake();

        $action = new PrepareDailyCountSummary;

        $mc1 = $action->execute($this->companyA, '2026-10-05');
        $mc2 = $action->execute($this->companyA, '2026-10-05');

        $this->assertNotNull($mc1->summary_sent_at);
        $this->assertEquals($mc1->id, $mc2->id);

        Notification::assertSentTo(
            $this->primaryAdminA,
            DailyCountSummaryNotification::class,
            1
        );
    }

    public function test_review_does_not_lock_count(): void
    {
        $action = new AcknowledgeDailyCount;

        $mc = $action->execute($this->companyA, '2026-10-05', $this->primaryAdminA);

        $this->assertEquals($this->primaryAdminA->id, $mc->reviewed_by);
        $this->assertNotNull($mc->reviewed_at);
        $this->assertNull($mc->locked_at);
    }

    public function test_anomaly_deviation_flag_requires_min_history(): void
    {
        $detector = new DetectCountAnomalies;

        // 1. With NO history, deviation flag is NOT set even if current count differs
        $flagsNoHistory = $detector->execute($this->companyA, '2026-10-10', [
            'base_eligible_count' => 10,
            'skip_count' => 0,
            'extra_count' => 0,
            'final_expected_count' => 10,
        ]);

        $this->assertNotContains('count_deviation', $flagsNoHistory);

        // 2. Add 5 locked historical days averaging 10
        for ($day = 1; $day <= 5; $day++) {
            MealCount::create([
                'company_id' => $this->companyA->id,
                'tiffin_service_id' => $this->tiffinService->id,
                'date' => "2026-10-0{$day}",
                'base_eligible_count' => 10,
                'skip_count' => 0,
                'extra_count' => 0,
                'final_expected_count' => 10,
                'adjusted_total' => 10,
                'breakdown' => [],
                'status' => 'confirmed',
                'locked_at' => now(),
            ]);
        }

        // Current count is 20 (100% deviation vs 10 avg) -> Should trigger count_deviation flag
        $flagsWithHistory = $detector->execute($this->companyA, '2026-10-10', [
            'base_eligible_count' => 20,
            'skip_count' => 0,
            'extra_count' => 0,
            'final_expected_count' => 20,
        ]);

        $this->assertContains('count_deviation', $flagsWithHistory);
    }

    public function test_spike_and_zero_anomaly_flags(): void
    {
        $detector = new DetectCountAnomalies;

        // Extra meals > 10 => spike
        $flagsSpikeExtra = $detector->execute($this->companyA, '2026-10-05', [
            'base_eligible_count' => 10,
            'skip_count' => 0,
            'extra_count' => 15,
            'final_expected_count' => 25,
        ]);
        $this->assertContains('spike', $flagsSpikeExtra);

        // Final count 0 => zero
        $flagsZero = $detector->execute($this->companyA, '2026-10-05', [
            'base_eligible_count' => 0,
            'skip_count' => 0,
            'extra_count' => 0,
            'final_expected_count' => 0,
        ]);
        $this->assertContains('zero', $flagsZero);
    }

    public function test_backup_escalation_only_when_unreviewed_and_has_anomalies(): void
    {
        Notification::fake();

        // Create draft MealCount with anomaly flag
        MealCount::create([
            'company_id' => $this->companyA->id,
            'tiffin_service_id' => $this->tiffinService->id,
            'date' => '2026-10-05',
            'base_eligible_count' => 10,
            'skip_count' => 0,
            'extra_count' => 15,
            'final_expected_count' => 25,
            'breakdown' => [],
            'anomaly_flags' => ['spike'],
            'status' => 'draft',
        ]);

        $escalateAction = new EscalateUnreviewedAnomaly;
        $escalateAction->execute($this->companyA, '2026-10-05');

        Notification::assertSentTo(
            $this->backupAdminA,
            AnomalyEscalationNotification::class
        );
    }

    public function test_summary_notifies_all_company_admins_if_no_primary_admin(): void
    {
        Notification::fake();

        // Clear primary admin setting
        $this->companyA->setting->update(['primary_admin_id' => null]);

        $action = new PrepareDailyCountSummary;
        $action->execute($this->companyA, '2026-10-05');

        Notification::assertSentTo($this->primaryAdminA, DailyCountSummaryNotification::class);
        Notification::assertSentTo($this->backupAdminA, DailyCountSummaryNotification::class);
    }

    public function test_vendor_receives_count_ready_notification_without_pii(): void
    {
        Notification::fake();

        $action = new ConfirmDailyCount;
        $action->execute($this->companyA, '2026-10-05', $this->primaryAdminA);

        Notification::assertSentTo(
            $this->tiffinUser,
            VendorCountReadyNotification::class,
            function (VendorCountReadyNotification $notification) {
                $data = $notification->toArray($this->tiffinUser);

                return $data['company_name'] === 'Company A' &&
                       $data['date'] === '2026-10-05' &&
                       ! str_contains(json_encode($data), 'Employee');
            }
        );
    }

    public function test_vendor_receives_late_change_notification(): void
    {
        Notification::fake();

        // Lock snapshot first
        $mc = MealCount::create([
            'company_id' => $this->companyA->id,
            'tiffin_service_id' => $this->tiffinService->id,
            'date' => '2026-10-05',
            'base_eligible_count' => 10,
            'skip_count' => 0,
            'extra_count' => 0,
            'final_expected_count' => 10,
            'adjusted_total' => 10,
            'breakdown' => [],
            'status' => 'confirmed',
            'locked_at' => now(),
        ]);

        $action = new RecordPostCutoffChange;
        $action->execute($this->companyA, '2026-10-05', 2, 'Guest VIP addition', $this->primaryAdminA);

        Notification::assertSentTo(
            $this->tiffinUser,
            VendorLateChangeNotification::class,
            function (VendorLateChangeNotification $notification) {
                $data = $notification->toArray($this->tiffinUser);

                return $data['change_quantity'] === 2 && $data['reason'] === 'Guest VIP addition';
            }
        );
    }

    public function test_cross_company_user_cannot_acknowledge(): void
    {
        $userB = User::create([
            'name' => 'User B',
            'email' => 'b@company-b.com',
            'password' => bcrypt('password'),
            'role' => 'company_admin',
            'company_id' => $this->companyB->id,
        ]);

        $action = new AcknowledgeDailyCount;

        $this->expectException(MealRuleViolation::class);
        $action->execute($this->companyA, '2026-10-05', $userB);
    }

    public function test_scheduler_runs_summary_escalation_and_cutoff_catchup(): void
    {
        Notification::fake();

        // Travel to Monday 10:45 AM IST (summary >= 10:30, escalate >= 10:45, cutoff 11:00)
        Carbon::setTestNow('2026-10-05 10:45:00');

        $this->artisan('mealbells:process-cutoff')
            ->assertExitCode(0);

        Notification::assertSentTo($this->primaryAdminA, DailyCountSummaryNotification::class);
    }
}
