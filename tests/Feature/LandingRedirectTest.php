<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\TiffinService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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

    /**
     * A guest now meets the public landing page, which is static HTML built by
     * `npm run build:landing` - Astro is its own build tool and cannot share
     * the application's Vite pipeline.
     */
    public function test_a_guest_sees_the_landing_page_when_it_has_been_built(): void
    {
        $landing = tempnam(sys_get_temp_dir(), 'landing').'.html';
        file_put_contents($landing, '<!doctype html><title>MealBells</title><h1>Hello</h1>');

        config()->set('mealbells.landing_page', $landing);

        $response = $this->get('/');

        $response->assertStatus(200);

        // response()->file() returns a BinaryFileResponse, whose body is not
        // materialised in a test - so the file it is serving is asserted
        // directly rather than its rendered content.
        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $this->assertSame($landing, $response->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString('Hello', file_get_contents($landing));

        @unlink($landing);
    }

    /**
     * And is sent to sign-in when it has not been.
     *
     * public/landing is gitignored build output, so a fresh clone, a CI job
     * that only runs `npm run build`, or a deploy that forgets the second
     * command all have no landing page. That must be a redirect, not a 500.
     */
    public function test_a_guest_is_sent_to_the_sign_in_page_when_the_landing_page_is_missing(): void
    {
        config()->set('mealbells.landing_page', '/tmp/mealbells-landing-that-does-not-exist.html');

        $this->get('/')->assertRedirect(route('login'));
    }

    /**
     * Signed-in users are unaffected either way: they are redirected before the
     * landing page is even looked for.
     */
    public function test_the_landing_page_never_shows_to_a_signed_in_user(): void
    {
        $landing = tempnam(sys_get_temp_dir(), 'landing').'.html';
        file_put_contents($landing, '<!doctype html><h1>Public landing</h1>');

        config()->set('mealbells.landing_page', $landing);

        $this->actingAs($this->user('company_admin'))
            ->get('/')
            ->assertRedirect('/company-admin/dashboard');

        @unlink($landing);
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

        config()->set('mealbells.landing_page', '/tmp/mealbells-landing-that-does-not-exist.html');

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
