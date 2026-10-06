<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CompanyTiffinAssignment;
use App\Models\Employee;
use App\Models\MealAdjustment;
use App\Models\MealCount;
use App\Models\MealCountChange;
use App\Models\Skip;
use App\Models\TiffinService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyDailyPageTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected TiffinService $tiffin;

    protected User $admin;

    protected Employee $alice;

    protected Employee $bob;

    protected Employee $carol;

    protected string $today = '2026-10-05';

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

        foreach (['alice' => 'ACME001', 'bob' => 'ACME002', 'carol' => 'ACME003'] as $prop => $code) {
            $this->$prop = Employee::create([
                'company_id' => $this->company->id, 'employee_code' => $code,
                'name' => ucfirst($prop), 'status' => 'active', 'is_meal_eligible' => true,
            ]);
        }
    }

    /** @return array<string, mixed> */
    protected function pageProps(?string $date = null): array
    {
        $response = $this->actingAs($this->admin)->get('/company-admin/daily?date='.($date ?? $this->today));
        $response->assertStatus(200);

        return $response->getOriginalContent()->getData()['page']['props'];
    }

    protected function lockDay(string $date): MealCount
    {
        return MealCount::create([
            'company_id' => $this->company->id,
            'tiffin_service_id' => $this->tiffin->id,
            'date' => $date,
            'base_eligible_count' => 3, 'skip_count' => 0, 'extra_count' => 0,
            'final_expected_count' => 3, 'breakdown' => [],
            'status' => 'auto_confirmed', 'lock_type' => 'auto', 'locked_at' => now(),
        ]);
    }

    public function test_the_page_loads_with_the_count_roster_and_status(): void
    {
        $props = $this->pageProps();

        $this->assertEquals($this->today, $props['date']);
        $this->assertTrue($props['is_meal_day']);
        $this->assertEquals('estimate', $props['status']);
        $this->assertEquals(3, $props['count']['base_eligible_count']);
        $this->assertEquals(3, $props['count']['final_expected_count']);
        $this->assertCount(3, $props['employees_for_search']);
        $this->assertEquals('11:00', $props['cutoff_time']);
        $this->assertGreaterThan(0, $props['seconds_left']);
        $this->assertNull($props['snapshot']);
    }

    public function test_marking_a_skip_lowers_the_expected_count(): void
    {
        $this->actingAs($this->admin)->post('/company-admin/skips', [
            'employee_id' => $this->alice->id,
            'date' => $this->today,
            'source' => 'hr',
            'reason' => 'Out of office',
        ])->assertSessionHasNoErrors();

        $props = $this->pageProps();

        $this->assertEquals(1, $props['count']['skip_count']);
        $this->assertEquals(2, $props['count']['final_expected_count']);
        $this->assertEquals('hr', $props['skips'][0]['source']);
    }

    public function test_cancelling_a_skip_restores_the_count(): void
    {
        $this->actingAs($this->admin)->post('/company-admin/skips', [
            'employee_id' => $this->alice->id, 'date' => $this->today, 'source' => 'hr',
        ]);

        $skip = Skip::sole();

        $this->actingAs($this->admin)->delete("/company-admin/skips/{$skip->id}")
            ->assertSessionHasNoErrors();

        $this->assertNotNull($skip->fresh()->cancelled_at);
        $this->assertEquals(3, $this->pageProps()['count']['final_expected_count']);
    }

    public function test_a_self_skip_keeps_its_source_in_the_roster(): void
    {
        // First-source-wins, so an employee's own skip is not relabelled when HR
        // looks at the day.
        Skip::create([
            'company_id' => $this->company->id, 'employee_id' => $this->bob->id,
            'date' => $this->today, 'source' => 'self', 'reason' => 'Fasting',
        ]);

        $props = $this->pageProps();

        $this->assertEquals('self', collect($props['skips'])->firstWhere('employee_id', $this->bob->id)['source']);
    }

    public function test_bulk_skip_marks_every_selected_employee(): void
    {
        $this->actingAs($this->admin)->post('/company-admin/skips/bulk', [
            'employee_ids' => [$this->alice->id, $this->bob->id],
            'dates' => [$this->today],
            'source' => 'leave',
            'reason' => 'Team offsite',
        ])->assertSessionHasNoErrors();

        $props = $this->pageProps();

        $this->assertEquals(2, $props['count']['skip_count']);
        $this->assertEquals(1, $props['count']['final_expected_count']);
    }

    public function test_an_extra_meal_raises_the_count_and_can_be_cancelled(): void
    {
        $this->actingAs($this->admin)->post('/company-admin/extra-meals', [
            'date' => $this->today, 'quantity' => 4, 'type' => 'guest', 'reason' => 'Client visit',
        ])->assertSessionHasNoErrors();

        $props = $this->pageProps();
        $this->assertEquals(4, $props['count']['extra_count']);
        $this->assertEquals(7, $props['count']['final_expected_count']);

        $adjustment = MealAdjustment::sole();

        $this->actingAs($this->admin)->delete("/company-admin/extra-meals/{$adjustment->id}")
            ->assertSessionHasNoErrors();

        $this->assertEquals(3, $this->pageProps()['count']['final_expected_count']);
    }

    public function test_a_locked_day_reports_locked_and_refuses_a_skip_with_a_readable_message(): void
    {
        $this->lockDay($this->today);

        $props = $this->pageProps();
        $this->assertEquals('locked', $props['status']);
        $this->assertEquals('auto', $props['snapshot']['lock_type']);

        $response = $this->actingAs($this->admin)->post('/company-admin/skips', [
            'employee_id' => $this->alice->id, 'date' => $this->today, 'source' => 'hr',
        ]);

        // A guard refusal, not a 500.
        $response->assertRedirect();
        $response->assertSessionHasErrors('skip');
        $this->assertStringContainsString('locked', strtolower(session('errors')->first('skip')));
        $this->assertEquals(0, Skip::count());
    }

    public function test_a_post_cutoff_change_moves_the_adjusted_total_on_a_locked_day(): void
    {
        $this->lockDay($this->today);

        $this->actingAs($this->admin)->post('/company-admin/late-changes', [
            'date' => $this->today, 'change_quantity' => 4, 'reason' => 'Agreed with the vendor',
        ])->assertSessionHasNoErrors();

        $props = $this->pageProps();

        $this->assertEquals(3, $props['count']['final_expected_count']);
        $this->assertEquals(7, $props['count']['adjusted_total']);
        $this->assertCount(1, $props['count']['changes']);
        $this->assertEquals(1, MealCountChange::count());
    }

    public function test_a_post_cutoff_change_is_refused_before_the_count_is_locked(): void
    {
        $response = $this->actingAs($this->admin)->post('/company-admin/late-changes', [
            'date' => $this->today, 'change_quantity' => 2, 'reason' => 'Too early',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('late_change');
        $this->assertEquals(0, MealCountChange::count());
    }

    public function test_reviewing_the_count_stamps_it_and_the_page_reports_reviewed(): void
    {
        $this->lockDay($this->today);

        $this->actingAs($this->admin)->post('/company-admin/daily/acknowledge', ['date' => $this->today])
            ->assertSessionHasNoErrors();

        $props = $this->pageProps();

        $this->assertNotNull($props['snapshot']['reviewed_at']);
        $this->assertEquals('Acme HR', $props['snapshot']['reviewed_by']);
    }

    public function test_a_past_cutoff_skip_is_refused_with_a_readable_message(): void
    {
        // 12:00 against an 11:00 cutoff, with nothing locked yet.
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', 'Asia/Kolkata'));

        $response = $this->actingAs($this->admin)->post('/company-admin/skips', [
            'employee_id' => $this->alice->id, 'date' => $this->today, 'source' => 'hr',
        ]);

        $response->assertSessionHasErrors('skip');
        $this->assertEquals(0, Skip::count());
        $this->assertEquals(0, $this->pageProps()['seconds_left']);
    }

    public function test_a_non_meal_day_is_reported_as_such(): void
    {
        // Saturday.
        $props = $this->pageProps('2026-10-10');

        $this->assertFalse($props['is_meal_day']);
        $this->assertEquals(0, $props['count']['final_expected_count']);
    }

    public function test_another_companys_admin_cannot_see_this_count(): void
    {
        $other = Company::create(['name' => 'Other Corp', 'code' => 'OTHR01']);
        $otherAdmin = User::create([
            'name' => 'Other HR', 'email' => 'hr@other.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $other->id,
        ]);

        $props = $this->actingAs($otherAdmin)->get('/company-admin/daily?date='.$this->today)
            ->getOriginalContent()->getData()['page']['props'];

        $this->assertEquals(0, $props['count']['base_eligible_count']);
        $this->assertEmpty($props['employees_for_search']);
    }
}
