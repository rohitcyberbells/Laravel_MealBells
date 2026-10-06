<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CompanyTiffinAssignment;
use App\Models\Employee;
use App\Models\TiffinService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected TiffinService $tiffin;

    /** @var array<string, User> */
    protected array $users = [];

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

        $this->users['super_admin'] = User::create([
            'name' => 'Root', 'email' => 'root@mealbells.test', 'password' => bcrypt('password'),
            'role' => 'super_admin',
        ]);

        $this->users['tiffin_admin'] = User::create([
            'name' => 'Chef Rahul', 'email' => 'vendor@mealbells.test', 'password' => bcrypt('password'),
            'role' => 'tiffin_admin', 'tiffin_service_id' => $this->tiffin->id,
        ]);

        $this->users['company_admin'] = User::create([
            'name' => 'Acme HR', 'email' => 'hr@acme.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->company->id,
        ]);

        $this->users['employee'] = User::create([
            'name' => 'Alice', 'email' => 'alice@acme.test', 'password' => bcrypt('password'),
            'role' => 'employee', 'company_id' => $this->company->id, 'login_code' => 'ACME001',
        ]);

        Employee::create([
            'company_id' => $this->company->id, 'user_id' => $this->users['employee']->id,
            'employee_code' => 'ACME001', 'name' => 'Alice',
            'status' => 'active', 'is_meal_eligible' => true,
        ]);
    }

    /** @return array<int, string> */
    protected function navHrefsFor(string $role): array
    {
        $landing = config("navigation.items.{$role}")[0]['href'];

        $response = $this->actingAs($this->users[$role])->get($landing);
        $response->assertStatus(200);

        $navigation = $response->getOriginalContent()->getData()['page']['props']['navigation'];

        return collect($navigation)->pluck('href')->all();
    }

    /**
     * Every GET page this role is allowed to reach, taken from the router rather
     * than a hand-written list - so a new page with no menu item fails here.
     *
     * @return array<int, string>
     */
    protected function routeGetPathsFor(string $role): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array('GET', $route->methods(), true))
            ->filter(fn ($route) => in_array("role:{$role}", $route->gatherMiddleware(), true))
            ->map(fn ($route) => '/'.ltrim($route->uri(), '/'))
            ->unique()
            ->values()
            ->all();
    }

    public static function roleProvider(): array
    {
        return [
            'super admin' => ['super_admin'],
            'tiffin admin' => ['tiffin_admin'],
            'company admin' => ['company_admin'],
            'employee' => ['employee'],
        ];
    }

    #[DataProvider('roleProvider')]
    public function test_every_page_a_role_can_reach_has_a_menu_item(string $role): void
    {
        $this->assertEqualsCanonicalizing(
            $this->routeGetPathsFor($role),
            $this->navHrefsFor($role),
            "The sidebar for {$role} does not match the pages that role can reach."
        );
    }

    #[DataProvider('roleProvider')]
    public function test_a_role_is_never_shown_another_roles_links(string $role): void
    {
        $own = $this->navHrefsFor($role);

        foreach (array_keys(config('navigation.items')) as $otherRole) {
            if ($otherRole === $role) {
                continue;
            }

            foreach (config("navigation.items.{$otherRole}") as $item) {
                $this->assertNotContains(
                    $item['href'],
                    $own,
                    "{$role} was shown {$otherRole}'s link {$item['href']}."
                );
            }
        }
    }

    #[DataProvider('roleProvider')]
    public function test_every_menu_item_actually_loads(string $role): void
    {
        foreach (config("navigation.items.{$role}") as $item) {
            $this->actingAs($this->users[$role])
                ->get($item['href'])
                ->assertStatus(200, "{$item['href']} did not load for {$role}.");
        }
    }

    #[DataProvider('roleProvider')]
    public function test_a_role_cannot_open_another_roles_pages(string $role): void
    {
        foreach (array_keys(config('navigation.items')) as $otherRole) {
            if ($otherRole === $role) {
                continue;
            }

            foreach (config("navigation.items.{$otherRole}") as $item) {
                $this->actingAs($this->users[$role])
                    ->get($item['href'])
                    ->assertStatus(403, "{$role} was able to open {$item['href']}.");
            }
        }
    }

    public function test_the_sidebar_shows_the_workspace_each_role_belongs_to(): void
    {
        $this->assertEquals('Platform', $this->workspaceFor('super_admin'));
        $this->assertEquals('Annapurna Tiffin', $this->workspaceFor('tiffin_admin'));
        $this->assertEquals('Acme Industries', $this->workspaceFor('company_admin'));
        $this->assertEquals('Acme Industries', $this->workspaceFor('employee'));
    }

    protected function workspaceFor(string $role): ?string
    {
        $landing = config("navigation.items.{$role}")[0]['href'];

        return $this->actingAs($this->users[$role])->get($landing)
            ->getOriginalContent()->getData()['page']['props']['workspace'];
    }

    public function test_the_signed_in_user_is_shared_for_the_sidebar_footer(): void
    {
        $props = $this->actingAs($this->users['company_admin'])
            ->get('/company-admin/dashboard')
            ->getOriginalContent()->getData()['page']['props'];

        $this->assertEquals('Acme HR', $props['auth']['user']['name']);
        $this->assertEquals('hr@acme.test', $props['auth']['user']['email']);

        // The sidebar needs the role only indirectly; the password must never
        // travel with it.
        $this->assertArrayNotHasKey('password', $props['auth']['user']);
    }

    public function test_a_guest_page_carries_no_menu(): void
    {
        $props = $this->get('/login')->getOriginalContent()->getData()['page']['props'];

        $this->assertEmpty($props['navigation']);
        $this->assertNull($props['auth']['user']);
    }
}
