<?php

namespace Database\Seeders;

use App\Actions\Employee\CreateEmployeeLogins;
use App\Actions\Meal\CalculateExpectedMeals;
use App\Models\Company;
use App\Models\CompanyCalendarDay;
use App\Models\CompanyHrmsConnection;
use App\Models\CompanySetting;
use App\Models\CompanyTiffinAssignment;
use App\Models\Employee;
use App\Models\HrmsWebhookEvent;
use App\Models\MealAdjustment;
use App\Models\MealCount;
use App\Models\MealCountChange;
use App\Models\MenuItem;
use App\Models\RecurringSkip;
use App\Models\Skip;
use App\Models\TiffinService;
use App\Models\User;
use App\Models\WeeklyMenu;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * A full, walkable demo: two companies on one vendor, a published week of menus,
 * skips from every source, a locked day with a late change on it, a holiday,
 * guest meals, and logins for every role.
 *
 * Dates are derived from today rather than hardcoded, so the demo still reads
 * correctly whenever it is run.
 *
 * No HRMS data is seeded on purpose - the webhook flow has its own demo path
 * through hrms:simulate.
 */
class DemoSeeder extends Seeder
{
    protected const PASSWORD = 'demo1234';

    /** Known so a demo can sign a webhook by hand or with hrms:simulate. */
    protected const HRMS_SECRET = 'whsec_demo_acme_0123456789';

    protected string $timezone = 'Asia/Kolkata';

    /** @var array<int, array{role: string, company: string, login: string, password: string, note: string}> */
    protected array $logins = [];

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            $this->command?->error('DemoSeeder only runs in local or testing.');

            return;
        }

        $tiffin = $this->seedVendor();

        $acme = $this->seedCompany('Acme Industries', 'ACME', $tiffin, employeeCount: 30, cutoff: '11:00:00');
        $northwind = $this->seedCompany('Northwind Traders', 'NWND', $tiffin, employeeCount: 10, cutoff: '10:30:00');

        $this->seedWeeklyMenu($tiffin);

        $this->seedActivity($acme);
        $this->seedActivity($northwind, lighter: true);

        // Only Acme is wired to an HR system, so the connect screen has
        // something to show while Northwind demonstrates the unconnected state.
        $this->seedHrmsConnection($acme);

        $this->printLogins();
    }

    protected function seedVendor(): TiffinService
    {
        $tiffin = TiffinService::create([
            'name' => 'Annapurna Gourmet Tiffin',
            'address' => '12 Kitchen Lane, Pune',
            'contact_phone' => '9820012345',
        ]);

        User::create([
            'name' => 'Platform Root',
            'email' => 'root@mealbells.test',
            'password' => Hash::make(self::PASSWORD),
            'role' => 'super_admin',
            'must_change_password' => false,
        ]);

        $this->logins[] = [
            'role' => 'super_admin', 'company' => '—',
            'login' => 'root@mealbells.test', 'password' => self::PASSWORD,
            'note' => 'Pairing, health, HRMS secrets',
        ];

        User::create([
            'name' => 'Chef Rahul',
            'email' => 'vendor@mealbells.test',
            'password' => Hash::make(self::PASSWORD),
            'role' => 'tiffin_admin',
            'tiffin_service_id' => $tiffin->id,
            'must_change_password' => false,
        ]);

        $this->logins[] = [
            'role' => 'tiffin_admin', 'company' => 'Annapurna',
            'login' => 'vendor@mealbells.test', 'password' => self::PASSWORD,
            'note' => 'Weekly menu, preparation view',
        ];

        return $tiffin;
    }

    protected function seedCompany(string $name, string $code, TiffinService $tiffin, int $employeeCount, string $cutoff): Company
    {
        $company = Company::create([
            'name' => $name,
            'code' => $code.'01',
            'address' => '440 Business Park, Pune',
            'contact_phone' => '9820054321',
        ]);

        $domain = strtolower($code).'.test';

        $primaryAdmin = User::create([
            'name' => "{$name} HR",
            'email' => "hr@{$domain}",
            'password' => Hash::make(self::PASSWORD),
            'role' => 'company_admin',
            'company_id' => $company->id,
            'must_change_password' => false,
        ]);

        $backupAdmin = User::create([
            'name' => "{$name} HR Backup",
            'email' => "hr.backup@{$domain}",
            'password' => Hash::make(self::PASSWORD),
            'role' => 'company_admin',
            'company_id' => $company->id,
            'must_change_password' => false,
        ]);

        $this->logins[] = [
            'role' => 'company_admin', 'company' => $name,
            'login' => "hr@{$domain}", 'password' => self::PASSWORD,
            'note' => 'Primary admin (gets the daily summary)',
        ];

        $this->logins[] = [
            'role' => 'company_admin', 'company' => $name,
            'login' => "hr.backup@{$domain}", 'password' => self::PASSWORD,
            'note' => 'Backup admin (gets anomaly escalations)',
        ];

        CompanySetting::create([
            'company_id' => $company->id,
            'cutoff_time' => $cutoff,
            'timezone' => $this->timezone,
            'wfh_auto_skip' => true,
            'meal_days' => [1, 2, 3, 4, 5],
            'primary_admin_id' => $primaryAdmin->id,
            'backup_admin_id' => $backupAdmin->id,
        ]);

        CompanyTiffinAssignment::create([
            'company_id' => $company->id,
            'tiffin_service_id' => $tiffin->id,
            'is_active' => true,
            'assigned_at' => Carbon::today($this->timezone)->subMonth(),
        ]);

        $this->seedEmployees($company, $code, $domain, $employeeCount);

        return $company;
    }

    protected function seedEmployees(Company $company, string $code, string $domain, int $count): void
    {
        $firstNames = ['Aarav', 'Diya', 'Vivaan', 'Ananya', 'Arjun', 'Isha', 'Kabir', 'Meera', 'Rohan', 'Saanvi',
            'Advait', 'Kiara', 'Reyansh', 'Myra', 'Ayaan', 'Aadhya', 'Krishna', 'Anika', 'Ishaan', 'Navya',
            'Dhruv', 'Riya', 'Atharv', 'Pari', 'Shaurya', 'Avni', 'Veer', 'Tara', 'Nikhil', 'Sara'];

        foreach (range(1, $count) as $index) {
            $employeeCode = sprintf('%s%03d', $code, $index);
            $name = $firstNames[($index - 1) % count($firstNames)]." {$code}";

            // A couple of each company are deliberately not eligible, so the
            // base count never trivially equals the headcount.
            $isInactive = $index === $count;
            $isIneligible = $index === $count - 1;

            // One employee with no address, so the demo also covers the company
            // code route and the admin notice that goes with it.
            $hasEmail = $index !== 2;

            $employee = Employee::create([
                'company_id' => $company->id,
                'employee_code' => $employeeCode,
                // The HR system's own reference, so the connect screen can
                // suggest one and hrms:simulate has something to aim at.
                'external_id' => "HR-{$employeeCode}",
                'name' => $name,
                // One obvious domain for every employee, so a demo login is
                // recognisable at a glance.
                'email' => $hasEmail ? strtolower($employeeCode).'@demo.test' : null,
                'attendance_source' => 'manual',
                'is_meal_eligible' => ! $isIneligible,
                'status' => $isInactive ? 'inactive' : 'active',
            ]);

            // Logins for the first three of each company: enough to demo the
            // employee portal without printing a forty-row table.
            if ($index <= 3) {
                $user = User::create([
                    'name' => $name,
                    // The employee with no address still needs an account, so it
                    // gets the same stand-in the provisioning action would use.
                    'email' => $employee->email ?: CreateEmployeeLogins::placeholderEmail($company, $employeeCode),
                    'password' => Hash::make(self::PASSWORD),
                    'role' => 'employee',
                    'company_id' => $company->id,
                    'login_code' => $employeeCode,
                    // False on purpose: true would drop every demo login onto
                    // the change-password screen before anything can be shown.
                    'must_change_password' => false,
                ]);

                $employee->update(['user_id' => $user->id]);

                $this->logins[] = [
                    'role' => 'employee', 'company' => $company->name,
                    'login' => $employee->email ?: "{$company->code} / {$employeeCode}",
                    'password' => self::PASSWORD,
                    'note' => $employee->email
                        ? ($index === 1 ? 'Has a recurring Monday skip' : 'Employee portal')
                        : 'No email: company code + employee code only',
                ];
            }
        }
    }

    protected function seedWeeklyMenu(TiffinService $tiffin): void
    {
        $meals = [
            'Monday' => 'Rajma Chawal, Salad, Gulab Jamun',
            'Tuesday' => 'Paneer Butter Masala, Jeera Rice, Roti',
            'Wednesday' => 'Chole Bhature, Pickle, Buttermilk',
            'Thursday' => 'Veg Biryani, Raita, Papad',
            'Friday' => 'Dal Tadka, Aloo Gobi, Roti, Kheer',
        ];

        // This week and next, so the vendor view and the employee's next seven
        // days both have something to show.
        foreach ([0, 1] as $weekOffset) {
            $menu = WeeklyMenu::create([
                'tiffin_service_id' => $tiffin->id,
                'week_start_date' => Carbon::today($this->timezone)->startOfWeek()->addWeeks($weekOffset)->toDateString(),
                'status' => 'published',
            ]);

            foreach ($meals as $day => $description) {
                MenuItem::create([
                    'weekly_menu_id' => $menu->id,
                    'day_of_week' => $day,
                    'meal_description' => $description,
                ]);
            }
        }
    }

    protected function seedActivity(Company $company, bool $lighter = false): void
    {
        $admin = User::where('company_id', $company->id)->where('role', 'company_admin')->orderBy('id')->first();
        $employees = Employee::where('company_id', $company->id)
            ->where('status', 'active')
            ->where('is_meal_eligible', true)
            ->orderBy('id')
            ->get();

        $lockedDay = $this->previousMealDay();
        $nextDay = $this->nextMealDay();
        $laterDay = $this->nextMealDay(2);
        $holiday = $this->nextMealDay(4);

        // One holiday, so the calendar override shows up in the engine.
        CompanyCalendarDay::create([
            'company_id' => $company->id,
            'date' => $holiday,
            'type' => 'holiday',
            'note' => 'Founders Day',
            'created_by' => $admin->id,
        ]);

        // Skips from every source the engine understands.
        $sources = ['leave', 'wfh', 'hr', 'self'];

        foreach ($sources as $offset => $source) {
            $employee = $employees[$offset] ?? null;

            if (! $employee) {
                continue;
            }

            Skip::create([
                'company_id' => $company->id,
                'employee_id' => $employee->id,
                'date' => $nextDay,
                'source' => $source,
                'reason' => ucfirst($source).' recorded for the demo',
                'created_by' => in_array($source, ['hr', 'leave', 'wfh'], true) ? $admin->id : $employee->user_id,
            ]);
        }

        // A recurring rule with its generated skip, so the recurring source is
        // represented on a later day too.
        if ($employees->first()) {
            RecurringSkip::create([
                'company_id' => $company->id,
                'employee_id' => $employees->first()->id,
                'weekday' => 1,
                'starts_on' => Carbon::today($this->timezone)->toDateString(),
                'active' => true,
                'created_by' => $employees->first()->user_id ?? $admin->id,
            ]);

            Skip::create([
                'company_id' => $company->id,
                'employee_id' => $employees->first()->id,
                'date' => $laterDay,
                'source' => 'recurring',
                'reason' => 'Recurring skip rule',
            ]);
        }

        // One cancelled skip, so the demo shows a withdrawn one too.
        if ($employees->count() > 4) {
            $cancelled = Skip::create([
                'company_id' => $company->id,
                'employee_id' => $employees[4]->id,
                'date' => $laterDay,
                'source' => 'self',
                'reason' => 'Changed their mind',
            ]);

            $cancelled->update(['cancelled_at' => now(), 'cancelled_by' => $employees[4]->user_id ?? $admin->id]);
        }

        // Guest meals.
        MealAdjustment::create([
            'company_id' => $company->id,
            'date' => $nextDay,
            'quantity' => $lighter ? 2 : 6,
            'type' => 'guest',
            'reason' => 'Client visit',
            'created_by' => $admin->id,
        ]);

        MealAdjustment::create([
            'company_id' => $company->id,
            'date' => $laterDay,
            'quantity' => 3,
            'type' => 'visitor',
            'reason' => 'Auditors on site',
            'created_by' => $admin->id,
        ]);

        $this->seedLockedDay($company, $admin, $lockedDay, $employees);
    }

    /**
     * A past day that is locked, with a late change recorded against it - the
     * state the post-cutoff flow produces.
     *
     * @param  Collection<int, Employee>  $employees
     */
    protected function seedLockedDay(Company $company, User $admin, string $date, $employees): void
    {
        if ($employees->first()) {
            Skip::create([
                'company_id' => $company->id,
                'employee_id' => $employees->first()->id,
                'date' => $date,
                'source' => 'leave',
                'reason' => 'Was on leave',
                'created_by' => $admin->id,
            ]);
        }

        $numbers = (new CalculateExpectedMeals)->execute($company, $date);

        $snapshot = MealCount::create([
            'company_id' => $company->id,
            'tiffin_service_id' => $company->activeAssignment->tiffin_service_id,
            'date' => $date,
            'base_eligible_count' => $numbers['base_eligible_count'],
            'skip_count' => $numbers['skip_count'],
            'extra_count' => $numbers['extra_count'],
            'final_expected_count' => $numbers['final_expected_count'],
            'breakdown' => $numbers['breakdown'],
            'status' => 'auto_confirmed',
            'lock_type' => 'auto',
            'locked_at' => Carbon::parse($date, $this->timezone)->setTime(11, 0),
            'summary_sent_at' => Carbon::parse($date, $this->timezone)->setTime(10, 30),
            'reviewed_by' => $admin->id,
            'reviewed_at' => Carbon::parse($date, $this->timezone)->setTime(10, 40),
        ]);

        MealCountChange::create([
            'meal_count_id' => $snapshot->id,
            'change_quantity' => 4,
            'reason' => 'Four extra plates agreed with the vendor after cutoff',
            'requested_by' => $admin->id,
        ]);

        MealCountChange::create([
            'meal_count_id' => $snapshot->id,
            'change_quantity' => -1,
            'reason' => 'One guest cancelled',
            'requested_by' => $admin->id,
        ]);
    }

    protected function previousMealDay(): string
    {
        $cursor = Carbon::today($this->timezone)->subDay();

        while ($cursor->isWeekend()) {
            $cursor->subDay();
        }

        return $cursor->toDateString();
    }

    protected function nextMealDay(int $skipCount = 1): string
    {
        $cursor = Carbon::today($this->timezone);

        for ($found = 0; $found < $skipCount;) {
            $cursor->addDay();

            if (! $cursor->isWeekend()) {
                $found++;
            }
        }

        return $cursor->toDateString();
    }

    /**
     * An HRMS connection with a known secret, plus one event of each outcome, so
     * the connect screen and the health page are not blank on a fresh demo.
     *
     * The events are written directly rather than delivered, because a real
     * delivery needs a running server and a queue worker - `hrms:simulate` is
     * there for showing the live path.
     */
    protected function seedHrmsConnection(Company $company): void
    {
        CompanyHrmsConnection::create([
            'company_id' => $company->id,
            'webhook_secret' => self::HRMS_SECRET,
            'auth' => 'signature',
            'secret_rotated_at' => now()->subDays(3),
        ]);

        $employee = Employee::where('company_id', $company->id)
            ->whereNotNull('external_id')
            ->orderBy('id')
            ->first();

        $reference = $employee?->external_id ?? 'HR-1';
        $appliedDay = $this->nextMealDay(3);

        // The applied event claims it created a skip, so it has to have one -
        // otherwise the connect screen shows an outcome with nothing behind it.
        // A later employee is used so the simulator demo has a clean subject.
        $appliedFor = Employee::where('company_id', $company->id)
            ->where('status', 'active')
            ->where('is_meal_eligible', true)
            ->orderBy('id')
            ->skip(5)
            ->first() ?? $employee;

        if ($appliedFor) {
            Skip::create([
                'company_id' => $company->id,
                'employee_id' => $appliedFor->id,
                'date' => $appliedDay,
                'source' => 'leave',
                'reason' => 'Approved in the HR system',
                'external_ref' => 'LV-9001',
            ]);
        }

        HrmsWebhookEvent::create([
            'company_id' => $company->id,
            'external_event_id' => 'evt_demo_applied',
            'event_type' => 'approved',
            'leave_external_id' => 'LV-9001',
            'occurred_at' => now()->subHours(5),
            'payload' => $this->hrmsPayload('evt_demo_applied', 'leave_approved', 'LV-9001', $appliedFor?->external_id ?? $reference, $appliedDay),
            'status' => HrmsWebhookEvent::STATUS_APPLIED,
            'processed_at' => now()->subHours(5),
            'result' => [
                'applied_days' => [$appliedDay],
                'already_days' => [],
                'blocked_days' => [],
                'released_days' => [],
                'release_blocked' => [],
                'non_meal_days' => [],
                'outside_window_days' => [],
                'notes' => [],
            ],
        ]);

        HrmsWebhookEvent::create([
            'company_id' => $company->id,
            'external_event_id' => 'evt_demo_blocked',
            'event_type' => 'approved',
            'leave_external_id' => 'LV-9002',
            'occurred_at' => now()->subHours(4),
            'payload' => $this->hrmsPayload('evt_demo_blocked', 'leave_approved', 'LV-9002', 'HR-NOT-MAPPED', $appliedDay),
            'status' => HrmsWebhookEvent::STATUS_BLOCKED,
            'processed_at' => now()->subHours(4),
            'result' => [
                'applied_days' => [],
                'already_days' => [],
                'blocked_days' => [],
                'released_days' => [],
                'release_blocked' => [],
                'non_meal_days' => [],
                'outside_window_days' => [],
                'notes' => ["No employee in this company matches 'HR-NOT-MAPPED'."],
            ],
        ]);

        // Older than the cancellation below, so it reads as genuinely superseded.
        HrmsWebhookEvent::create([
            'company_id' => $company->id,
            'external_event_id' => 'evt_demo_stale',
            'event_type' => 'approved',
            'leave_external_id' => 'LV-9003',
            'occurred_at' => now()->subHours(3),
            'payload' => $this->hrmsPayload('evt_demo_stale', 'leave_approved', 'LV-9003', $reference, $appliedDay),
            'status' => HrmsWebhookEvent::STATUS_STALE,
            'processed_at' => now()->subHours(2),
            'result' => ['notes' => ['A newer applied event for this leave already superseded it.']],
        ]);

        HrmsWebhookEvent::create([
            'company_id' => $company->id,
            'external_event_id' => 'evt_demo_cancelled',
            'event_type' => 'cancelled',
            'leave_external_id' => 'LV-9003',
            'occurred_at' => now()->subHours(2),
            'payload' => [
                'event_id' => 'evt_demo_cancelled',
                'event_type' => 'leave_cancelled',
                'occurred_at' => now()->subHours(2)->toIso8601String(),
                'leave' => ['id' => 'LV-9003'],
            ],
            'status' => HrmsWebhookEvent::STATUS_APPLIED,
            'processed_at' => now()->subHours(2),
            'result' => [
                'applied_days' => [],
                'already_days' => [],
                'blocked_days' => [],
                'released_days' => [],
                'release_blocked' => [],
                'non_meal_days' => [],
                'outside_window_days' => [],
                'notes' => [],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function hrmsPayload(string $eventId, string $eventType, string $leaveId, string $employeeRef, string $date): array
    {
        return [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'occurred_at' => now()->toIso8601String(),
            'leave' => [
                'id' => $leaveId,
                'employee_id' => $employeeRef,
                'from_date' => $date,
                'to_date' => $date,
                'type' => 'Casual Leave',
                'reason' => 'Seeded demo event',
            ],
        ];
    }

    protected function printLogins(): void
    {
        if (! $this->command) {
            return;
        }

        $this->command->newLine();
        $this->command->info('MealBells demo data seeded.');
        $this->command->newLine();

        $this->command->table(
            ['Role', 'Company', 'Login', 'Password', 'What to look at'],
            array_map(fn (array $row) => [
                $row['role'], $row['company'], $row['login'], $row['password'], $row['note'],
            ], $this->logins)
        );

        $this->command->newLine();
        $this->command->line('Employees sign in with their email, or with the company code and their employee code.');
        $this->command->line('Acme HRMS webhook secret: '.self::HRMS_SECRET);
        $this->command->line('Run `php artisan queue:work` for notifications, and `php artisan schedule:work` for the cutoff.');
        $this->command->newLine();
    }
}
