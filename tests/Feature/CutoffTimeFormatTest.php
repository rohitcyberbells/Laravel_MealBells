<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * One stored shape for cutoff_time.
 *
 * The column is a `time`, so PostgreSQL hands back 'HH:MM:SS' whatever was
 * written, while SQLite keeps the string verbatim - which is how the settings
 * form's '09:30' and the seeder's '11:00:00' came to sit in the same column
 * locally and not in production. Nothing was visibly broken, because every
 * reader happened to parse both; the risk was the next reader that did not, and
 * a local test passing on a shape production never produces.
 */
class CutoffTimeFormatTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inertia.ssr.enabled', false);

        $this->company = Company::create(['name' => 'Acme Industries', 'code' => 'ACME01']);

        $this->admin = User::create([
            'name' => 'Acme HR', 'email' => 'hr@acme.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->company->id,
        ]);
    }

    /** @return array<string, mixed> */
    protected function payload(string $cutoff): array
    {
        return [
            'cutoff_time' => $cutoff,
            'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => false,
            'meal_days' => [1, 2, 3, 4, 5],
        ];
    }

    public function test_both_accepted_shapes_are_stored_the_same_way(): void
    {
        foreach (['09:30' => '09:30:00', '09:30:00' => '09:30:00', '9:30' => '09:30:00'] as $given => $expected) {
            $setting = CompanySetting::create([
                'company_id' => $this->company->id, 'cutoff_time' => $given,
                'timezone' => 'Asia/Kolkata', 'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
            ]);

            $this->assertSame($expected, $setting->fresh()->cutoff_time, "stored shape for '{$given}'");

            $setting->delete();
        }
    }

    public function test_the_form_and_the_seeder_agree(): void
    {
        $this->actingAs($this->admin)
            ->put('/company-admin/settings', $this->payload('10:30'))
            ->assertSessionHasNoErrors();

        $fromForm = CompanySetting::where('company_id', $this->company->id)->firstOrFail()->cutoff_time;

        $other = Company::create(['name' => 'Northwind', 'code' => 'NWND01']);
        $fromSeederShape = CompanySetting::create([
            'company_id' => $other->id, 'cutoff_time' => '10:30:00',
            'timezone' => 'Asia/Kolkata', 'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ])->fresh()->cutoff_time;

        $this->assertSame($fromSeederShape, $fromForm);
    }

    public function test_a_time_that_is_not_a_time_is_rejected_rather_than_stored(): void
    {
        $this->actingAs($this->admin)
            ->put('/company-admin/settings', $this->payload('12pm'))
            ->assertSessionHasErrors('cutoff_time');

        $this->assertNull(CompanySetting::where('company_id', $this->company->id)->first());
    }

    /**
     * The one that mattered. '12pm' through the old `required` rule parsed as
     * hour 0, so the cutoff silently became midnight: the count locks before
     * anyone can change a meal, and nothing anywhere says why.
     */
    public function test_an_unparseable_time_cannot_reach_the_column_by_any_route(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CompanySetting::create([
            'company_id' => $this->company->id, 'cutoff_time' => '12pm',
            'timezone' => 'Asia/Kolkata', 'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);
    }

    public function test_an_impossible_time_of_day_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CompanySetting::normalizeCutoffTime('25:00');
    }

    public function test_every_reader_gets_the_same_two_numbers_from_either_shape(): void
    {
        $short = new CompanySetting(['cutoff_time' => '09:30']);
        $long = new CompanySetting(['cutoff_time' => '09:30:00']);

        $this->assertSame([9, 30], $short->cutoffHourMinute());
        $this->assertSame([9, 30], $long->cutoffHourMinute());
        $this->assertSame('09:30', $short->cutoffLabel());
        $this->assertSame('09:30', $long->cutoffLabel());
    }

    /**
     * There used to be two different fallbacks for the same missing value:
     * '11:00' in four places and '10:30:00' in MealGuard's message. A company
     * with no settings row showed one cutoff on its dashboard and a different
     * one in the error it got for missing that cutoff.
     */
    public function test_a_company_with_no_settings_row_gets_one_fallback_everywhere(): void
    {
        $this->assertSame(
            CompanySetting::DEFAULT_CUTOFF_TIME,
            '11:00:00',
            'the fallback is declared in one place',
        );

        $this->assertSame([11, 0], CompanySetting::cutoffHourMinuteFor(null));
        $this->assertSame('11:00', CompanySetting::cutoffLabelFor(null));
    }
}
