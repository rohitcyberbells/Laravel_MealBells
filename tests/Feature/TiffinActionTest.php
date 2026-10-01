<?php

namespace Tests\Feature;

use App\Actions\Meal\CreateDailyOverride;
use App\Actions\Menu\SaveWeeklyMenu;
use App\Events\DailyMealOverridden;
use App\Events\WeeklyMenuPublished;
use App\Models\Company;
use App\Models\CompanyTiffinAssignment;
use App\Models\TiffinService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TiffinActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_save_weekly_menu_action_dispatches_event_and_notifies_company_admin(): void
    {
        Event::fake([WeeklyMenuPublished::class]);
        Notification::fake();

        $tiffin = TiffinService::create([
            'name' => 'Royal Tiffin',
            'address' => '123 Main St',
            'contact_phone' => '1234567890',
        ]);

        $company = Company::create([
            'name' => 'Acme Corp',
            'address' => '456 Tech Park',
            'contact_phone' => '0987654321',
        ]);

        CompanyTiffinAssignment::create([
            'company_id' => $company->id,
            'tiffin_service_id' => $tiffin->id,
            'is_active' => true,
            'assigned_at' => now(),
        ]);

        $tiffinAdmin = User::create([
            'name' => 'Chef Rahul',
            'email' => 'chef@royaltiffin.com',
            'password' => bcrypt('password'),
            'role' => 'tiffin_admin',
            'tiffin_service_id' => $tiffin->id,
        ]);

        $companyAdmin = User::create([
            'name' => 'Priya Admin',
            'email' => 'priya@acme.com',
            'password' => bcrypt('password'),
            'role' => 'company_admin',
            'company_id' => $company->id,
        ]);

        $action = new SaveWeeklyMenu;
        $menu = $action->execute($tiffinAdmin, [
            'week_start_date' => now()->startOfWeek()->toDateString(),
            'status' => 'published',
            'items' => [
                ['day_of_week' => 'monday', 'meal_description' => 'Paneer Butter Masala'],
                ['day_of_week' => 'tuesday', 'meal_description' => 'Dal Makhani'],
            ],
        ]);

        $this->assertEquals('published', $menu->status);
        Event::assertDispatched(WeeklyMenuPublished::class);
    }

    public function test_create_daily_override_action_dispatches_event(): void
    {
        Event::fake([DailyMealOverridden::class]);

        $tiffin = TiffinService::create([
            'name' => 'Royal Tiffin',
            'address' => '123 Main St',
            'contact_phone' => '1234567890',
        ]);

        $tiffinAdmin = User::create([
            'name' => 'Chef Rahul',
            'email' => 'chef2@royaltiffin.com',
            'password' => bcrypt('password'),
            'role' => 'tiffin_admin',
            'tiffin_service_id' => $tiffin->id,
        ]);

        $action = new CreateDailyOverride;
        $override = $action->execute($tiffinAdmin, [
            'meal_description' => 'Special Chole Bhature',
            'reason' => 'Chef Special Today',
        ]);

        $this->assertEquals('Special Chole Bhature', $override->meal_description);
        Event::assertDispatched(DailyMealOverridden::class);
    }
}
