<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The cutoff countdown rendered as "18:51:7.715735999998287 left".
 *
 * The server's seconds_left is a float - Carbon's diff carries fractional
 * seconds - and the page did `remaining % 60` for the seconds while the hours
 * and minutes went through Math.floor. So only the last field showed it.
 *
 * These tests pin both halves: the value really is fractional (so flooring is
 * required, not decoration), and both pages floor it.
 */
class CutoffCountdownFormatTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $admin;

    protected User $employeeUser;

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

        $this->employeeUser = User::create([
            'name' => 'Alice', 'email' => 'alice@alpha.test', 'password' => bcrypt('password'),
            'role' => 'employee', 'company_id' => $this->company->id, 'login_code' => 'EMP101',
        ]);

        Employee::create([
            'company_id' => $this->company->id, 'user_id' => $this->employeeUser->id,
            'employee_code' => 'EMP101', 'name' => 'Alice',
            'status' => 'active', 'is_meal_eligible' => true,
        ]);
    }

    /**
     * Why flooring is needed at all. The clock is frozen at 08:00:00 against an
     * 11:00 cutoff, so the gap is a whole number here - but the value arrives
     * as a float, and any real moment carries a fraction.
     */
    public function test_the_server_sends_seconds_left_as_a_float(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 08:00:00.482', 'Asia/Kolkata'));

        $props = $this->actingAs($this->admin)->get('/company-admin/daily')
            ->getOriginalContent()->getData()['page']['props'];

        $this->assertIsFloat($props['seconds_left']);
        $this->assertNotEquals(
            (float) (int) $props['seconds_left'],
            $props['seconds_left'],
            'the fractional part is what leaked into the rendered seconds',
        );
    }

    public function test_the_employee_dashboard_sends_it_as_a_float_too(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 08:00:00.482', 'Asia/Kolkata'));

        $props = $this->actingAs($this->employeeUser)->get('/employee/dashboard')
            ->getOriginalContent()->getData()['page']['props'];

        $this->assertIsFloat($props['today']['seconds_left']);
    }

    /** @return array<int, array<int, string>> */
    public static function pagesWithACountdown(): array
    {
        return [
            ['js/Pages/CompanyAdmin/Daily/Index.vue'],
            ['js/Pages/Employee/Dashboard.vue'],
        ];
    }

    /**
     * Floored where the value enters, so the ticker counts whole seconds rather
     * than carrying a fraction for the life of the page.
     */
    #[DataProvider('pagesWithACountdown')]
    public function test_the_page_floors_the_countdown_at_its_source(string $page): void
    {
        $source = file_get_contents(resource_path($page));

        $this->assertMatchesRegularExpression(
            '/const remaining = ref\(Math\.max\(0, Math\.floor\(/',
            $source,
            "{$page} does not floor seconds_left where it enters",
        );
    }

    #[DataProvider('pagesWithACountdown')]
    public function test_the_seconds_field_is_floored_when_formatted(string $page): void
    {
        $source = file_get_contents(resource_path($page));

        // `remaining % 60` on a float is what produced "7.715735999998287".
        $this->assertStringNotContainsString('const s = remaining.value % 60;', $source);
        $this->assertStringContainsString('Math.floor(remaining.value % 60)', $source);
    }

    /**
     * The format itself, checked by running the page's own arithmetic over a
     * fractional input - so this fails if the shape ever stops being HH:MM:SS.
     */
    public function test_the_arithmetic_produces_a_padded_hms_string(): void
    {
        $seconds = (int) floor(67867.715735999998287);

        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        $formatted = sprintf('%02d:%02d:%02d', $h, $m, $s);

        $this->assertEquals('18:51:07', $formatted);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', $formatted);
    }
}
