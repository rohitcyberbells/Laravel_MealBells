<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\MealCount;
use App\Models\TiffinService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Behaviour that differs between SQLite and PostgreSQL.
 *
 * The suite runs on SQLite in memory, production runs PostgreSQL, and the two
 * disagree in ways a test does not notice until something is already live.
 * These assert the behaviour the application needs, so they hold on whichever
 * driver they are run against - and in CI they are run against both.
 */
class DriverPortabilityTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inertia.ssr.enabled', false);

        $this->company = Company::create(['name' => 'Acme Industries', 'code' => 'ACME01']);

        CompanySetting::create([
            'company_id' => $this->company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);

        $this->admin = User::create([
            'name' => 'Acme HR', 'email' => 'hr@acme.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->company->id,
        ]);

        Employee::create([
            'company_id' => $this->company->id, 'employee_code' => 'ACME001',
            'name' => 'Alice Sharma', 'email' => 'Alice.Sharma@Acme.test',
            'status' => 'active', 'is_meal_eligible' => true,
        ]);
    }

    /** @return array<string, mixed> */
    protected function searchProps(string $term): array
    {
        $response = $this->actingAs($this->admin)->get('/company-admin/employees?search='.urlencode($term));
        $response->assertStatus(200);

        return $response->getOriginalContent()->getData()['page']['props'];
    }

    /**
     * SQLite's LIKE is case-insensitive for ASCII; PostgreSQL's is not. So this
     * screen searched case-insensitively in development and case-sensitively in
     * production - typing 'alice' would not find 'Alice' on a real deployment,
     * and nothing in the suite said so.
     *
     * Be clear about what this test is worth: run on SQLite it passes with the
     * plain LIKE restored, because SQLite does not have the bug. It only bites
     * on PostgreSQL. That is the whole reason the CI workflow runs the suite
     * against both, and the reason this test lives here rather than being
     * mistaken for a local guard.
     */
    #[DataProvider('casings')]
    public function test_employee_search_ignores_case_on_either_driver(string $term): void
    {
        $found = $this->searchProps($term)['employees']['data'];

        $this->assertCount(1, $found, "searching '{$term}' found nothing");
        $this->assertSame('Alice Sharma', $found[0]['name']);
    }

    /** @return array<string, array<int, string>> */
    public static function casings(): array
    {
        return [
            'lowercase name' => ['alice'],
            'uppercase name' => ['ALICE'],
            'mixed name' => ['aLiCe'],
            'lowercase code' => ['acme001'],
            'uppercase code' => ['ACME001'],
            'lowercase email' => ['alice.sharma@acme.test'],
            'padded' => ['  alice  '],
        ];
    }

    public function test_a_search_that_matches_nothing_returns_nothing(): void
    {
        $this->assertCount(0, $this->searchProps('nobodyhere')['employees']['data']);
    }

    /**
     * SQLite stores whatever string it is given; PostgreSQL's `time` column
     * returns 'HH:MM:SS' whatever was written. Normalising on write is what
     * makes both drivers agree.
     */
    public function test_a_time_column_round_trips_in_one_shape(): void
    {
        $setting = CompanySetting::where('company_id', $this->company->id)->firstOrFail();

        $setting->update(['cutoff_time' => '09:30']);

        $this->assertSame('09:30:00', $setting->fresh()->cutoff_time);
    }

    /**
     * SQLite hands back 0 and 1 for booleans and PostgreSQL hands back true and
     * false, so anything comparing with === would differ. The cast is what
     * makes it not matter.
     */
    public function test_a_boolean_column_comes_back_as_a_boolean(): void
    {
        $setting = CompanySetting::where('company_id', $this->company->id)->firstOrFail();

        $this->assertIsBool($setting->wfh_auto_skip);
        $this->assertTrue($setting->wfh_auto_skip);
    }

    /**
     * The breakdown column is json on SQLite and suits jsonb on PostgreSQL.
     * An empty array must not come back as an empty string or null.
     */
    public function test_a_json_column_round_trips_as_an_array(): void
    {
        $tiffin = TiffinService::create([
            'name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890',
        ]);

        $count = MealCount::create([
            'company_id' => $this->company->id, 'tiffin_service_id' => $tiffin->id,
            'date' => '2026-10-05', 'base_eligible_count' => 1, 'skip_count' => 0, 'extra_count' => 0,
            'final_expected_count' => 1, 'status' => 'draft',
            'breakdown' => ['eligible' => 1, 'sources' => ['self' => 0]],
        ]);

        $fresh = $count->fresh();

        $this->assertIsArray($fresh->breakdown);
        $this->assertSame(1, $fresh->breakdown['eligible']);
        $this->assertSame(0, $fresh->breakdown['sources']['self']);
    }

    /**
     * The grouped tallies on the health page are raw SQL. PostgreSQL requires
     * every selected column to be grouped or aggregated and SQLite does not, so
     * a query that is fine locally can be rejected outright in production.
     */
    public function test_the_grouped_tallies_run_on_either_driver(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database', 'queue' => 'default',
            'payload' => '{}', 'exception' => 'x', 'failed_at' => now(),
        ]);

        $tallies = DB::table('failed_jobs')
            ->selectRaw('queue, count(*) as total')
            ->groupBy('queue')
            ->get();

        $this->assertCount(1, $tallies);
        $this->assertSame(1, (int) $tallies[0]->total);
    }

    /**
     * The queue-worker health check reads a unix timestamp out of an integer
     * column. PostgreSQL returns an int and SQLite a string, so the check has
     * to cope with both.
     */
    public function test_a_timestamp_integer_column_is_usable_from_either_driver(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
            'available_at' => now()->subMinutes(5)->timestamp,
            'created_at' => now()->subMinutes(5)->timestamp,
        ]);

        $oldest = DB::table('jobs')->whereNull('reserved_at')->min('available_at');

        $this->assertSame(5, (int) Carbon::createFromTimestamp($oldest)->diffInMinutes(now()));
    }
}
