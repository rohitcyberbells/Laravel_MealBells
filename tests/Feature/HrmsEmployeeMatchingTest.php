<?php

namespace Tests\Feature;

use App\Actions\Skip\ApplyHrmsLeaveEvent;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\User;
use App\Services\Hrms\HrmsActorResolver;
use App\Services\Hrms\HrmsEventMapper;
use App\Services\Hrms\HrmsEventPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Employee matching is external_id, then employee_code, then email.
 *
 * Email is the weakest key, so a match on it reports the vendor's id back and
 * the next event for that person resolves on the strong one. The write happens
 * in ApplyHrmsLeaveEvent, not the mapper, so mapping stays read-only.
 */
class HrmsEmployeeMatchingTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Company $other;

    protected Employee $byEmailOnly;

    protected Employee $alreadyLinked;

    protected HrmsEventMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);
        $this->other = Company::create(['name' => 'Beta Corp', 'code' => 'BETA1']);

        foreach ([$this->company, $this->other] as $company) {
            CompanySetting::create([
                'company_id' => $company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
                'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
            ]);

            User::create([
                'name' => "Admin {$company->code}", 'email' => "admin@{$company->code}.test",
                'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $company->id,
            ]);
        }

        $this->byEmailOnly = Employee::create([
            'company_id' => $this->company->id, 'employee_code' => 'EMP101', 'external_id' => null,
            'email' => 'alice@alpha.test', 'name' => 'Alice', 'status' => 'active', 'is_meal_eligible' => true,
        ]);

        $this->alreadyLinked = Employee::create([
            'company_id' => $this->company->id, 'employee_code' => 'EMP102', 'external_id' => 'HR-OLD',
            'email' => 'bob@alpha.test', 'name' => 'Bob', 'status' => 'active', 'is_meal_eligible' => true,
        ]);

        $this->mapper = new HrmsEventMapper(new HrmsActorResolver);
    }

    /** @return array<string, mixed> */
    protected function approval(?string $ref, ?string $email = null, string $leaveId = 'L-1'): array
    {
        return [
            'event_id' => 'evt-1',
            'event_type' => 'leave_approved',
            'occurred_at' => '2026-10-06T02:30:00Z',
            'leave' => array_filter([
                'id' => $leaveId,
                'employee_id' => $ref,
                'employee_email' => $email,
                'from_date' => '2026-10-08',
                'to_date' => '2026-10-08',
            ], fn ($v) => $v !== null),
        ];
    }

    protected function apply(HrmsEventPlan $plan): array
    {
        return app(ApplyHrmsLeaveEvent::class)->execute($this->company, $plan);
    }

    public function test_email_resolves_an_employee_the_strong_keys_miss(): void
    {
        $plan = $this->mapper->map($this->company, $this->approval('HR-NEW-1', 'alice@alpha.test'));

        $this->assertEquals(HrmsEventPlan::APPLY, $plan->resolution);
        $this->assertEquals($this->byEmailOnly->id, $plan->employee?->id);
    }

    public function test_email_matching_ignores_case(): void
    {
        $plan = $this->mapper->map($this->company, $this->approval('HR-NEW-1', 'ALICE@ALPHA.TEST'));

        $this->assertEquals($this->byEmailOnly->id, $plan->employee?->id);
    }

    public function test_an_email_match_reports_the_vendor_id_to_learn(): void
    {
        $plan = $this->mapper->map($this->company, $this->approval('HR-NEW-1', 'alice@alpha.test'));

        $this->assertEquals('HR-NEW-1', $plan->backfillExternalId);
    }

    /**
     * The mapper is read-only, which is what lets a dry run map an entire pull
     * without touching a row.
     */
    public function test_mapping_alone_writes_nothing(): void
    {
        $this->mapper->map($this->company, $this->approval('HR-NEW-1', 'alice@alpha.test'));

        $this->assertNull($this->byEmailOnly->fresh()->external_id);
    }

    public function test_applying_an_email_match_saves_the_vendor_id(): void
    {
        $plan = $this->mapper->map($this->company, $this->approval('HR-NEW-1', 'alice@alpha.test'));
        $this->apply($plan);

        $this->assertEquals('HR-NEW-1', $this->byEmailOnly->fresh()->external_id);
    }

    public function test_the_next_event_then_resolves_on_the_strong_key(): void
    {
        $this->apply($this->mapper->map($this->company, $this->approval('HR-NEW-1', 'alice@alpha.test')));

        // No email this time: external_id alone has to find her.
        $plan = $this->mapper->map($this->company, $this->approval('HR-NEW-1', null, 'L-2'));

        $this->assertEquals($this->byEmailOnly->id, $plan->employee?->id);
        $this->assertNull($plan->backfillExternalId, 'nothing left to learn');
    }

    public function test_an_existing_vendor_id_is_never_overwritten(): void
    {
        // Bob already carries HR-OLD; an event arriving with a different id must
        // not quietly repoint him.
        $plan = $this->mapper->map($this->company, $this->approval('HR-DIFFERENT', 'bob@alpha.test'));

        $this->assertEquals($this->alreadyLinked->id, $plan->employee?->id);
        $this->assertNull($plan->backfillExternalId);

        $this->apply($plan);
        $this->assertEquals('HR-OLD', $this->alreadyLinked->fresh()->external_id);
    }

    public function test_repeating_the_same_event_writes_nothing_the_second_time(): void
    {
        $plan = $this->mapper->map($this->company, $this->approval('HR-NEW-1', 'alice@alpha.test'));

        $first = $this->apply($plan);
        $this->assertNotEmpty(array_filter($first['notes'], fn ($n) => str_contains($n, 'Learned external_id')));

        $second = $this->apply($this->mapper->map($this->company, $this->approval('HR-NEW-1', 'alice@alpha.test')));
        $this->assertEmpty(array_filter($second['notes'], fn ($n) => str_contains($n, 'Learned external_id')));

        $this->assertEquals('HR-NEW-1', $this->byEmailOnly->fresh()->external_id);
    }

    public function test_external_id_still_wins_over_email(): void
    {
        // The email points at Alice, the external_id at Bob. The strong key wins.
        $plan = $this->mapper->map($this->company, $this->approval('HR-OLD', 'alice@alpha.test'));

        $this->assertEquals($this->alreadyLinked->id, $plan->employee?->id);
        $this->assertNull($plan->backfillExternalId);
    }

    public function test_employee_code_still_wins_over_email(): void
    {
        $plan = $this->mapper->map($this->company, $this->approval('EMP102', 'alice@alpha.test'));

        $this->assertEquals($this->alreadyLinked->id, $plan->employee?->id);
    }

    /**
     * A vendor that identifies people by address sends no separate email field.
     */
    public function test_a_reference_that_is_itself_an_address_matches(): void
    {
        $plan = $this->mapper->map($this->company, $this->approval('alice@alpha.test'));

        $this->assertEquals($this->byEmailOnly->id, $plan->employee?->id);
        // There is no vendor id to learn here - the address IS the reference, and
        // storing it as external_id would record the weak key as the strong one.
        $this->assertNull($plan->backfillExternalId);
    }

    public function test_an_address_from_another_company_does_not_resolve(): void
    {
        $outsider = Employee::create([
            'company_id' => $this->other->id, 'employee_code' => 'EMP201', 'external_id' => null,
            'email' => 'carol@beta.test', 'name' => 'Carol', 'status' => 'active', 'is_meal_eligible' => true,
        ]);

        $plan = $this->mapper->map($this->company, $this->approval('HR-NEW-9', 'carol@beta.test'));

        $this->assertEquals(HrmsEventPlan::UNKNOWN_EMPLOYEE, $plan->resolution);
        $this->assertNull($outsider->fresh()->external_id);
    }

    public function test_an_unmatched_address_is_an_unknown_employee(): void
    {
        $plan = $this->mapper->map($this->company, $this->approval('HR-NEW-9', 'nobody@alpha.test'));

        $this->assertEquals(HrmsEventPlan::UNKNOWN_EMPLOYEE, $plan->resolution);
    }

    public function test_an_employee_with_no_email_is_not_matched_by_a_blank(): void
    {
        $noEmail = Employee::create([
            'company_id' => $this->company->id, 'employee_code' => 'EMP103', 'external_id' => null,
            'email' => null, 'name' => 'Dave', 'status' => 'active', 'is_meal_eligible' => true,
        ]);

        $plan = $this->mapper->map($this->company, $this->approval('HR-NEW-9', ''));

        $this->assertEquals(HrmsEventPlan::UNKNOWN_EMPLOYEE, $plan->resolution);
        $this->assertNull($noEmail->fresh()->external_id);
    }
}
