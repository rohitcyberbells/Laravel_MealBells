<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyCalendarDay;
use App\Models\CompanySetting;
use App\Models\CompanyTiffinAssignment;
use App\Models\TiffinService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthMealDayTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;

    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $tiffin = TiffinService::create(['name' => 'Tiffin Co']);
        $this->companyA = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);

        CompanySetting::create([
            'company_id' => $this->companyA->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);

        CompanyTiffinAssignment::create([
            'company_id' => $this->companyA->id, 'tiffin_service_id' => $tiffin->id,
            'is_active' => true, 'assigned_at' => '2026-09-01',
        ]);

        $this->superAdmin = User::create([
            'name' => 'Root', 'email' => 'root@mealbells.test', 'password' => bcrypt('password'),
            'role' => 'super_admin',
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    protected function missing(): array
    {
        $response = $this->actingAs($this->superAdmin)->get('/super-admin/health');
        $response->assertStatus(200);

        return $response->getOriginalContent()->getData()['page']['props']['missing_snapshots_today'];
    }

    public function test_a_weekday_past_cutoff_with_no_snapshot_is_flagged(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', 'Asia/Kolkata'));

        $this->assertCount(1, $this->missing());
    }

    public function test_a_weekday_before_cutoff_is_not_flagged(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00', 'Asia/Kolkata'));

        $this->assertEmpty($this->missing());
    }

    public function test_a_weekend_is_not_flagged(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00', 'Asia/Kolkata'));

        $this->assertEmpty($this->missing());
    }

    public function test_a_weekday_declared_a_holiday_is_not_flagged(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', 'Asia/Kolkata'));

        CompanyCalendarDay::create([
            'company_id' => $this->companyA->id,
            'date' => '2026-10-05',
            'type' => 'holiday',
            'note' => 'Festival',
        ]);

        $this->assertEmpty($this->missing());
    }

    public function test_a_saturday_declared_a_working_day_is_flagged(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00', 'Asia/Kolkata'));

        CompanyCalendarDay::create([
            'company_id' => $this->companyA->id,
            'date' => '2026-10-10',
            'type' => 'working_day',
            'note' => 'Stock take',
        ]);

        $this->assertCount(1, $this->missing());
    }

    public function test_a_company_with_no_active_assignment_is_not_flagged(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', 'Asia/Kolkata'));

        CompanyTiffinAssignment::where('company_id', $this->companyA->id)
            ->update(['is_active' => false, 'unassigned_at' => '2026-10-01']);

        $this->assertEmpty($this->missing());
    }

    public function test_a_paired_company_with_no_settings_is_reported_as_unconfigured_not_missing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', 'Asia/Kolkata'));

        $tiffin = TiffinService::create(['name' => 'Second Tiffin']);
        $fresh = Company::create(['name' => 'Brand New Corp', 'code' => 'NEW1']);

        // storeCompany does not create a settings row, so this is the state every
        // newly paired company starts in.
        CompanyTiffinAssignment::create([
            'company_id' => $fresh->id, 'tiffin_service_id' => $tiffin->id,
            'is_active' => true, 'assigned_at' => '2026-09-01',
        ]);

        $response = $this->actingAs($this->superAdmin)->get('/super-admin/health');
        $props = $response->getOriginalContent()->getData()['page']['props'];

        $missing = collect($props['missing_snapshots_today'])->pluck('company_name')->all();
        $unconfigured = collect($props['unconfigured_companies'])->pluck('company_name')->all();

        // The cutoff job skips it, so a missing snapshot is not the problem and
        // flagging one was an alarm that could never be cleared.
        $this->assertNotContains('Brand New Corp', $missing);
        $this->assertContains('Brand New Corp', $unconfigured);

        // The configured company is still judged normally.
        $this->assertContains('Alpha Corp', $missing);
    }

    public function test_a_configured_company_is_never_listed_as_unconfigured(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', 'Asia/Kolkata'));

        $response = $this->actingAs($this->superAdmin)->get('/super-admin/health');
        $props = $response->getOriginalContent()->getData()['page']['props'];

        $this->assertEmpty($props['unconfigured_companies']);
    }

    public function test_another_companys_timezone_is_judged_on_its_own_clock(): void
    {
        // 2026-10-05 05:00 UTC is 10:30 IST (before an 11:00 IST cutoff) but
        // already 16:00 in Auckland, so a company there is past its cutoff.
        Carbon::setTestNow(Carbon::parse('2026-10-05 05:00:00', 'UTC'));

        $tiffin = TiffinService::create(['name' => 'NZ Tiffin']);
        $nz = Company::create(['name' => 'Kiwi Corp', 'code' => 'KIWI1']);

        CompanySetting::create([
            'company_id' => $nz->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Pacific/Auckland',
            'wfh_auto_skip' => false, 'meal_days' => [1, 2, 3, 4, 5],
        ]);

        CompanyTiffinAssignment::create([
            'company_id' => $nz->id, 'tiffin_service_id' => $tiffin->id,
            'is_active' => true, 'assigned_at' => '2026-09-01',
        ]);

        $flagged = collect($this->missing())->pluck('company_name')->all();

        $this->assertContains('Kiwi Corp', $flagged);
        $this->assertNotContains('Alpha Corp', $flagged);
    }
}
