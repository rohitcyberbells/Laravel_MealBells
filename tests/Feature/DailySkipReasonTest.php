<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\Skip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The roster shows why a day is skipped, not only that it is.
 *
 * "Skipped · leave" alone does not say which leave, so an admin cannot tell an
 * HRMS skip from one entered by hand. What it must never show is the vendor's
 * own reason text - that can carry medical detail, which is why the integration
 * does not fetch or store it in the first place.
 */
class DailySkipReasonTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $admin;

    protected Employee $fromHrms;

    protected Employee $byHand;

    protected Employee $noReason;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inertia.ssr.enabled', false);

        $this->company = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);

        CompanySetting::create([
            'company_id' => $this->company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);

        $this->admin = User::create([
            'name' => 'Alpha HR', 'email' => 'hr@alpha.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->company->id,
        ]);

        foreach (['EMP101' => 'fromHrms', 'EMP102' => 'byHand', 'EMP103' => 'noReason'] as $code => $prop) {
            $this->{$prop} = Employee::create([
                'company_id' => $this->company->id, 'employee_code' => $code,
                'name' => "Person {$code}", 'status' => 'active', 'is_meal_eligible' => true,
            ]);
        }

        // What the integration writes: our generated label, built from
        // whitelisted data only.
        Skip::create([
            'company_id' => $this->company->id, 'employee_id' => $this->fromHrms->id,
            'date' => '2026-10-08', 'source' => 'leave',
            'external_ref' => 'cp:leave:abc123', 'reason' => 'CyberPulse casual leave',
        ]);

        Skip::create([
            'company_id' => $this->company->id, 'employee_id' => $this->byHand->id,
            'date' => '2026-10-08', 'source' => 'hr', 'reason' => 'Agreed with the kitchen',
        ]);

        Skip::create([
            'company_id' => $this->company->id, 'employee_id' => $this->noReason->id,
            'date' => '2026-10-08', 'source' => 'self', 'reason' => null,
        ]);
    }

    /** @return array<string, mixed> */
    protected function dailyProps(string $date = '2026-10-08'): array
    {
        return $this->actingAs($this->admin)->get("/company-admin/daily?date={$date}")
            ->getOriginalContent()->getData()['page']['props'];
    }

    public function test_the_reason_reaches_the_page(): void
    {
        $this->actingAs($this->admin)->get('/company-admin/daily?date=2026-10-08')
            ->assertInertia(fn (Assert $page) => $page
                ->component('CompanyAdmin/Daily/Index')
                ->has('skips', 3)
                ->etc()
            );

        $byEmployee = collect($this->dailyProps()['skips'])->keyBy('employee_id');

        $this->assertEquals('CyberPulse casual leave', $byEmployee[$this->fromHrms->id]['reason']);
        $this->assertEquals('Agreed with the kitchen', $byEmployee[$this->byHand->id]['reason']);
        $this->assertNull($byEmployee[$this->noReason->id]['reason']);
    }

    /**
     * The label is ours and derived from whitelisted fields; the vendor's text
     * is never fetched, so it cannot be here to render.
     */
    public function test_the_label_is_ours_and_names_no_vendor_text(): void
    {
        $reason = collect($this->dailyProps()['skips'])
            ->firstWhere('employee_id', $this->fromHrms->id)['reason'];

        $this->assertStringStartsWith('CyberPulse', $reason);

        $body = $this->actingAs($this->admin)->get('/company-admin/daily?date=2026-10-08')->getContent();

        // Anything a real CyberPulse leave carried in its own reason field.
        foreach (['Dental surgery', 'Doctor visit', 'Vacation request', 'Personal work', 'Casual trip'] as $vendorText) {
            $this->assertStringNotContainsString($vendorText, $body);
        }
    }

    public function test_the_page_renders_the_reason_it_is_given(): void
    {
        $page = file_get_contents(resource_path('js/Pages/CompanyAdmin/Daily/Index.vue'));

        // A rename on either side would otherwise only show as a blank subtext.
        $this->assertStringContainsString('skipReason(skipByEmployee[employee.id])', $page);
        $this->assertStringContainsString('skip?.reason', $page);
        $this->assertArrayHasKey('skips', $this->dailyProps());
    }

    /**
     * A skip with no reason must render nothing rather than an empty line under
     * every such row.
     */
    public function test_a_skip_with_no_reason_shows_no_subtext(): void
    {
        $page = file_get_contents(resource_path('js/Pages/CompanyAdmin/Daily/Index.vue'));

        // The subtext is guarded on the trimmed reason being non-empty.
        $this->assertMatchesRegularExpression(
            '/v-if="skipReason\(skipByEmployee\[employee\.id\]\)"/',
            $page,
        );
        $this->assertStringContainsString('.trim() || null', $page);
    }

    public function test_a_cancelled_skip_contributes_no_reason(): void
    {
        Skip::where('employee_id', $this->fromHrms->id)->update([
            'cancelled_at' => now(), 'cancelled_by' => $this->admin->id, 'cancelled_source' => 'hrms',
        ]);

        // The page builds its map from uncancelled skips only, so the row goes
        // back to "Taking meal" with nothing underneath it.
        $active = collect($this->dailyProps()['skips'])
            ->filter(fn ($s) => $s['cancelled_at'] === null)
            ->pluck('employee_id');

        $this->assertNotContains($this->fromHrms->id, $active->all());
    }

    public function test_another_companys_skip_reason_is_not_exposed(): void
    {
        $other = Company::create(['name' => 'Beta Corp', 'code' => 'BETA1']);
        CompanySetting::create([
            'company_id' => $other->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);
        $outsider = Employee::create([
            'company_id' => $other->id, 'employee_code' => 'EMP201',
            'name' => 'Carol', 'status' => 'active', 'is_meal_eligible' => true,
        ]);
        Skip::create([
            'company_id' => $other->id, 'employee_id' => $outsider->id,
            'date' => '2026-10-08', 'source' => 'leave', 'reason' => 'BETA-ONLY-SECRET',
        ]);

        $body = $this->actingAs($this->admin)->get('/company-admin/daily?date=2026-10-08')->getContent();

        $this->assertStringNotContainsString('BETA-ONLY-SECRET', $body);
    }
}
