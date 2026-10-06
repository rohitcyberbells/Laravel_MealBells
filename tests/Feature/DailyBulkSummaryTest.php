<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CompanyTiffinAssignment;
use App\Models\Employee;
use App\Models\Skip;
use App\Models\TiffinService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * BulkRecordSkip returned a per-employee breakdown that was flashed and read by
 * nobody, so a bulk skip only ever said "processed".
 */
class DailyBulkSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $admin;

    protected Employee $alice;

    protected Employee $bob;

    protected Employee $carol;

    protected function setUp(): void
    {
        parent::setUp();

        $tiffin = TiffinService::create(['name' => 'Annapurna Tiffin']);
        $this->company = Company::create(['name' => 'Acme Industries', 'code' => 'ACME01']);

        CompanySetting::create([
            'company_id' => $this->company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);

        CompanyTiffinAssignment::create([
            'company_id' => $this->company->id, 'tiffin_service_id' => $tiffin->id,
            'is_active' => true, 'assigned_at' => '2026-09-01',
        ]);

        $this->admin = User::create([
            'name' => 'Acme HR', 'email' => 'hr@acme.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->company->id,
        ]);

        foreach (['alice' => 'ACME001', 'bob' => 'ACME002', 'carol' => 'ACME003'] as $prop => $code) {
            $this->$prop = Employee::create([
                'company_id' => $this->company->id, 'employee_code' => $code,
                'name' => ucfirst($prop), 'status' => 'active', 'is_meal_eligible' => true,
            ]);
        }
    }

    protected function bulk(array $ids, array $dates, string $source = 'hr')
    {
        return $this->actingAs($this->admin)
            ->from('/company-admin/daily?date=2026-10-06')
            ->post('/company-admin/skips/bulk', [
                'employee_ids' => $ids,
                'dates' => $dates,
                'source' => $source,
                'reason' => 'Team offsite',
            ]);
    }

    /** @return array<string, mixed> */
    protected function dailyProps(string $date = '2026-10-06'): array
    {
        return $this->actingAs($this->admin)->get("/company-admin/daily?date={$date}")
            ->getOriginalContent()->getData()['page']['props'];
    }

    public function test_the_summary_reaches_the_page_after_a_bulk_skip(): void
    {
        $this->bulk([$this->alice->id, $this->bob->id], ['2026-10-07'])
            ->assertRedirect();

        $this->actingAs($this->admin)->get('/company-admin/daily?date=2026-10-07')
            ->assertInertia(fn (Assert $page) => $page
                ->component('CompanyAdmin/Daily/Index')
                // Exactly the path the banner reads.
                ->where('flash.bulkSummary.created_count', 2)
                ->where('flash.bulkSummary.total_processed', 2)
                ->has('flash.bulkSummary.results', 2)
                ->etc()
            );
    }

    public function test_the_summary_separates_applied_from_everything_else(): void
    {
        // Already skipped by HR, so the bulk run cannot newly apply it.
        Skip::create([
            'company_id' => $this->company->id, 'employee_id' => $this->bob->id,
            'date' => '2026-10-07', 'source' => 'hr', 'reason' => 'HR decided',
        ]);

        $this->bulk([$this->alice->id, $this->bob->id], ['2026-10-07']);

        $summary = $this->dailyProps('2026-10-07')['flash']['bulkSummary'];

        $this->assertEquals(1, $summary['created_count']);
        $this->assertEquals(1, $summary['already_skipped_count']);
        $this->assertEquals(0, $summary['rejected_count']);

        $byEmployee = collect($summary['results'])->keyBy('employee_id');
        $this->assertEquals('created', $byEmployee[$this->alice->id]['status']);
        $this->assertEquals('already_skipped', $byEmployee[$this->bob->id]['status']);
    }

    public function test_a_refused_row_carries_the_reason_the_banner_lists(): void
    {
        $this->bob->update(['status' => 'inactive']);

        $this->bulk([$this->alice->id, $this->bob->id], ['2026-10-07']);

        $summary = $this->dailyProps('2026-10-07')['flash']['bulkSummary'];

        $this->assertEquals(1, $summary['rejected_count']);

        $refused = collect($summary['results'])->firstWhere('employee_id', $this->bob->id);

        $this->assertEquals('rejected', $refused['status']);
        $this->assertEquals('inactive_employee', $refused['reason_code']);
        $this->assertNotEmpty($refused['reason']);
        $this->assertEquals('2026-10-07', $refused['date']);
    }

    public function test_a_non_meal_day_is_reported_rather_than_silently_dropped(): void
    {
        // 2026-10-10 is a Saturday.
        $this->bulk([$this->alice->id], ['2026-10-07', '2026-10-10']);

        $summary = $this->dailyProps('2026-10-07')['flash']['bulkSummary'];

        $this->assertEquals(1, $summary['created_count']);
        $this->assertEquals(1, $summary['non_meal_day_count']);

        $weekend = collect($summary['results'])->firstWhere('date', '2026-10-10');
        $this->assertEquals('skipped_non_meal_day', $weekend['status']);
        $this->assertEquals('not_a_meal_day', $weekend['reason_code']);
    }

    public function test_the_banner_can_name_every_employee_it_reports(): void
    {
        $this->bulk([$this->alice->id, $this->carol->id], ['2026-10-07']);

        $props = $this->dailyProps('2026-10-07');
        $ids = collect($props['flash']['bulkSummary']['results'])->pluck('employee_id')->unique();

        // The banner maps ids through employees_for_search, so every id in the
        // summary has to be present there.
        $onPage = collect($props['employees_for_search'])->pluck('id');

        foreach ($ids as $id) {
            $this->assertTrue($onPage->contains($id), "Employee {$id} is missing from employees_for_search");
        }
    }

    public function test_the_summary_is_gone_on_a_reload(): void
    {
        $this->bulk([$this->alice->id], ['2026-10-07']);

        $this->assertNotNull($this->dailyProps('2026-10-07')['flash']['bulkSummary']);

        // Flash data, so it does not linger behind later navigation.
        $this->assertNull($this->dailyProps('2026-10-07')['flash']['bulkSummary']);
    }

    /**
     * The component and the shared key have to agree; a rename on either side
     * would otherwise only surface after someone ran a bulk skip.
     */
    public function test_the_page_reads_the_key_the_server_shares(): void
    {
        $daily = file_get_contents(resource_path('js/Pages/CompanyAdmin/Daily/Index.vue'));

        $this->assertStringContainsString('page.props.flash?.bulkSummary', $daily);
        $this->assertStringContainsString('bulkNotApplied', $daily);

        $this->assertArrayHasKey('bulkSummary', $this->dailyProps()['flash']);
    }
}
