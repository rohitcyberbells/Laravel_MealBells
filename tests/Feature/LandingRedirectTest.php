<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\TiffinService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * "/" is a signpost, not a page.
 *
 * Every screen in MealBells belongs to a signed-in role, so there is nothing
 * public to render. It used to serve a developer smoke-test page announcing the
 * framework version - the first thing anyone opening the app would see.
 */
class LandingRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected TiffinService $tiffin;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inertia.ssr.enabled', false);

        $this->tiffin = TiffinService::create(['name' => 'Tiffin Co', 'address' => 'Addr', 'contact_phone' => '9876543210']);
        $this->company = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);

        CompanySetting::create([
            'company_id' => $this->company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);
    }

    protected function user(string $role): User
    {
        return User::create([
            'name' => "A {$role}",
            'email' => str_replace('_', '-', $role).'@alpha.test',
            'password' => bcrypt('password'),
            'role' => $role,
            'company_id' => in_array($role, ['company_admin', 'employee'], true) ? $this->company->id : null,
            'tiffin_service_id' => $role === 'tiffin_admin' ? $this->tiffin->id : null,
            'login_code' => $role === 'employee' ? 'EMP101' : null,
        ]);
    }

    public function test_a_guest_is_sent_to_the_sign_in_page(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    /** @return array<int, array<int, string>> */
    public static function roleHomes(): array
    {
        return [
            ['super_admin', '/super-admin/dashboard'],
            ['tiffin_admin', '/tiffin-admin/dashboard'],
            ['company_admin', '/company-admin/dashboard'],
            ['employee', '/employee/dashboard'],
        ];
    }

    #[DataProvider('roleHomes')]
    public function test_a_signed_in_user_lands_on_their_own_home(string $role, string $home): void
    {
        $user = $this->user($role);

        if ($role === 'employee') {
            Employee::create([
                'company_id' => $this->company->id, 'user_id' => $user->id,
                'employee_code' => 'EMP101', 'name' => 'Alice',
                'status' => 'active', 'is_meal_eligible' => true,
            ]);
        }

        $this->actingAs($user)->get('/')->assertRedirect($home);
    }

    /**
     * Nobody should ever meet the framework's name, let alone the wrong version
     * of it - the scaffold said "Laravel 12" while the app runs 13.
     */
    public function test_the_scaffold_page_is_gone(): void
    {
        $this->assertFileDoesNotExist(resource_path('js/Pages/Welcome.vue'));

        $body = $this->followingRedirects()->get('/')->getContent();

        $this->assertStringNotContainsString('is working cleanly', $body);
        $this->assertStringNotContainsString('Laravel 12', $body);
    }

    public function test_the_sign_in_page_names_the_product(): void
    {
        $body = $this->get('/login')->getContent();

        $this->assertStringContainsString('MealBells', $body);
    }

    /**
     * The browser tab is part of the first impression, and it read "Laravel"
     * on every screen because APP_NAME was never changed.
     */
    public function test_the_page_title_is_the_product_not_the_framework(): void
    {
        $this->assertStringContainsString(
            'MealBells',
            file_get_contents(base_path('.env.example')),
            'APP_NAME in .env.example still names the framework',
        );

        $blade = file_get_contents(resource_path('views/app.blade.php'));
        $this->assertStringContainsString("config('app.name'", $blade);
        // And the fallback, for a deployment whose env is missing it.
        $this->assertDoesNotMatchRegularExpression("/config\('app\.name',\s*'Laravel'\)/", $blade);
    }
}
