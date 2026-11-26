<?php

namespace App\Console\Commands;

use App\Actions\Meal\CalculateExpectedMeals;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CompanyTiffinAssignment;
use App\Models\Employee;
use App\Models\MealCount;
use App\Models\MenuItem;
use App\Models\Skip;
use App\Models\TiffinService;
use App\Models\User;
use App\Models\WeeklyMenu;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Sample companies and kitchens, added beside whatever is already there.
 *
 * DemoSeeder builds a whole world from an empty database and would create a
 * second platform root on top of a real one. This is the other job: filling
 * out a working database that has real data in it, so screens built for many
 * tenants are not demonstrated with one.
 *
 * Everything it creates is removable, and --remove is the reason the accounts
 * all sit on one domain. That domain is the handle: the companies and kitchens
 * have no address of their own, so they are found through the accounts
 * attached to them. Nothing is deleted unless every account on it is ours.
 */
class SeedSampleOrgs extends Command
{
    protected $signature = 'mealbells:sample-orgs
                            {--remove : Delete everything this command created, and nothing else}';

    protected $description = 'Add sample companies and kitchens to the current database, or remove them again';

    /** The handle. Every account this command creates is on it, and nothing else is. */
    protected const DOMAIN = 'bells.com';

    protected const PASSWORD = 'demo1234';

    protected string $timezone = 'Asia/Kolkata';

    protected string $passwordHash = '';

    /** @var array<int, array{0: string, 1: string, 2: string, 3: string}> */
    protected const KITCHENS = [
        ['Sharda Kitchen', 'sharda', 'Shop 7, Mill Road, Kothrud, Pune 411038', '9823104477'],
        ['Gokul Bhojnalaya', 'gokul', '91 Station Road, Shivajinagar, Pune 411005', '8806621390'],
        ['Satkar Caterers', 'satkar', 'Unit 3, Canal Street, Yerwada, Pune 411006', '9850337214'],
    ];

    /**
     * Headcount, address, phone and cutoff all differ per row. Companies that
     * share an address and a round headcount are the first thing anybody being
     * shown a directory notices.
     *
     * @var array<int, array{0: string, 1: string, 2: int, 3: string, 4: string, 5: string}>
     */
    protected const COMPANIES = [
        ['Meridian Softworks', 'MERI', 46, 'Tower B, Cerebrum IT Park, Kalyani Nagar, Pune 411014', '9822017634', '11:00:00'],
        ['Kaveri Textiles', 'KAVE', 62, 'Plot 14, MIDC Bhosari, Pune 411026', '8605443120', '10:30:00'],
        ['Sunrise Logistics', 'SUNR', 24, 'Warehouse 9, Talawade Road, Pune 412114', '9890226581', '10:00:00'],
        ['Deccan Pharma', 'DECC', 55, 'Plot 22, Pharma Zone, Chakan MIDC, Pune 410501', '8975330916', '11:30:00'],
        ['Nalanda EdTech', 'NALA', 27, '301 Sanas Plaza, Viman Nagar, Pune 411014', '9028614477', '12:00:00'],
    ];

    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('mealbells:sample-orgs only runs in local or testing.');

            return self::FAILURE;
        }

        return $this->option('remove') ? $this->remove() : $this->add();
    }

    protected function add(): int
    {
        if (User::where('email', 'like', '%@'.self::DOMAIN)->exists()) {
            $this->warn('Sample orgs are already present. Nothing was added.');
            $this->line('Run with --remove first if you want them rebuilt.');

            return self::SUCCESS;
        }

        $this->passwordHash = Hash::make(self::PASSWORD);

        DB::transaction(function () {
            $kitchens = [];

            foreach (self::KITCHENS as [$name, $slug, $address, $phone]) {
                $kitchen = TiffinService::create([
                    'name' => $name,
                    'address' => $address,
                    'contact_phone' => $phone,
                ]);

                User::create([
                    'name' => $name.' Admin',
                    'email' => $slug.'@'.self::DOMAIN,
                    'password' => $this->passwordHash,
                    'role' => 'tiffin_admin',
                    'tiffin_service_id' => $kitchen->id,
                    'must_change_password' => false,
                ]);

                $this->seedMenu($kitchen);

                $kitchens[] = $kitchen;
            }

            foreach (self::COMPANIES as $index => [$name, $code, $headcount, $address, $phone, $cutoff]) {
                $this->seedCompany($name, $code, $kitchens[$index % count($kitchens)], $headcount, $address, $phone, $cutoff);
            }
        });

        $this->report();

        return self::SUCCESS;
    }

    /**
     * Delete what this command added, and refuse anything it did not.
     *
     * The companies and kitchens carry no mark of their own, so they are found
     * through their accounts. A company with even one account that is not ours
     * is left alone and named: that is either real data or somebody's work on
     * top of the sample, and both are worth more than a tidy database.
     */
    protected function remove(): int
    {
        $ours = User::where('email', 'like', '%@'.self::DOMAIN)->get();

        if ($ours->isEmpty()) {
            $this->info('No sample orgs found. Nothing was removed.');

            return self::SUCCESS;
        }

        $companyIds = $ours->pluck('company_id')->filter()->unique();
        $kitchenIds = $ours->pluck('tiffin_service_id')->filter()->unique();

        $sharedCompanies = User::whereIn('company_id', $companyIds)
            ->where('email', 'not like', '%@'.self::DOMAIN)
            ->pluck('company_id')->unique();

        $sharedKitchens = User::whereIn('tiffin_service_id', $kitchenIds)
            ->where('email', 'not like', '%@'.self::DOMAIN)
            ->pluck('tiffin_service_id')->unique();

        if ($sharedCompanies->isNotEmpty() || $sharedKitchens->isNotEmpty()) {
            $this->error('Refusing: some of these have accounts that are not sample accounts.');

            foreach (Company::whereIn('id', $sharedCompanies)->get() as $company) {
                $this->line("  company: {$company->name}");
            }

            foreach (TiffinService::whereIn('id', $sharedKitchens)->get() as $kitchen) {
                $this->line("  kitchen: {$kitchen->name}");
            }

            $this->newLine();
            $this->line('Nothing was removed. Detach those accounts first, or delete them by hand.');

            return self::FAILURE;
        }

        $counts = DB::transaction(function () use ($ours, $companyIds, $kitchenIds) {
            /*
             * Orgs before accounts. Every child table cascades from companies
             * and tiffin_services, and both models are soft-deleting, so this
             * has to be forceDelete or the rows survive with the parent only
             * marked gone. Deleting the accounts first would also cascade into
             * meal_adjustments through created_by before their company is out
             * of the way.
             */
            $companies = Company::whereIn('id', $companyIds)->get();
            $kitchens = TiffinService::whereIn('id', $kitchenIds)->get();

            foreach ($companies as $company) {
                $company->forceDelete();
            }

            foreach ($kitchens as $kitchen) {
                $kitchen->forceDelete();
            }

            $accounts = $ours->count();

            User::whereIn('id', $ours->pluck('id'))->delete();

            return [$companies->count(), $kitchens->count(), $accounts];
        });

        [$companies, $kitchens, $accounts] = $counts;

        $this->info("Removed {$companies} companies, {$kitchens} kitchens and {$accounts} accounts.");
        $this->line('Everything on @'.self::DOMAIN.' is gone. Nothing else was touched.');

        return self::SUCCESS;
    }

    protected function seedCompany(string $name, string $code, TiffinService $kitchen, int $headcount, string $address, string $phone, string $cutoff): void
    {
        $company = Company::create([
            'name' => $name,
            'code' => $code.'01',
            'address' => $address,
            'contact_phone' => $phone,
        ]);

        $slug = strtolower($code);

        $primary = User::create([
            'name' => "{$name} HR",
            'email' => "hr.{$slug}@".self::DOMAIN,
            'password' => $this->passwordHash,
            'role' => 'company_admin',
            'company_id' => $company->id,
            'must_change_password' => false,
        ]);

        $backup = User::create([
            'name' => "{$name} HR Backup",
            'email' => "hr.{$slug}.backup@".self::DOMAIN,
            'password' => $this->passwordHash,
            'role' => 'company_admin',
            'company_id' => $company->id,
            'must_change_password' => false,
        ]);

        CompanySetting::create([
            'company_id' => $company->id,
            'cutoff_time' => $cutoff,
            'timezone' => $this->timezone,
            'wfh_auto_skip' => true,
            'meal_days' => [1, 2, 3, 4, 5],
            'primary_admin_id' => $primary->id,
            'backup_admin_id' => $backup->id,
        ]);

        CompanyTiffinAssignment::create([
            'company_id' => $company->id,
            'tiffin_service_id' => $kitchen->id,
            'is_active' => true,
            'assigned_at' => Carbon::today($this->timezone)->subMonth(),
        ]);

        $this->seedEmployees($company, $code, $headcount);
        $this->seedActivity($company, $primary);
    }

    protected function seedEmployees(Company $company, string $code, int $count): void
    {
        $firstNames = ['Aarav', 'Diya', 'Vivaan', 'Ananya', 'Arjun', 'Isha', 'Kabir', 'Meera', 'Rohan', 'Saanvi',
            'Advait', 'Kiara', 'Reyansh', 'Myra', 'Ayaan', 'Aadhya', 'Krishna', 'Anika', 'Ishaan', 'Navya',
            'Dhruv', 'Riya', 'Atharv', 'Pari', 'Shaurya', 'Avni', 'Veer', 'Tara', 'Nikhil', 'Sara'];

        $surnames = ['Sharma', 'Iyer', 'Patil', 'Nair', 'Deshmukh', 'Rao', 'Kulkarni', 'Banerjee', 'Gupta',
            'Chauhan', 'Menon', 'Joshi', 'Reddy', 'Pillai', 'Shetty', 'Verma', 'Mehta', 'Bhatt', 'Kaur',
            'Saxena', 'Naik', 'Dubey', 'Sinha', 'Trivedi', 'Jain', 'Ghosh', 'Pandey', 'Bose', 'Chopra',
            'Mishra', 'Agarwal', 'Thakur', 'Rane', 'Salunkhe', 'Mahajan', 'Bhosale', 'Chavan', 'Gokhale',
            'Kamat', 'Dalvi', 'Phadke', 'Sawant', 'Borkar', 'Kadam', 'Gaikwad', 'Prabhu', 'Hegde'];

        // Each company starts at its own place in both lists, so five rosters
        // do not all open with Aarav Sharma, Diya Iyer, Vivaan Patil.
        $firstOffset = crc32($code) % count($firstNames);
        $lastOffset = crc32(strrev($code)) % count($surnames);

        $now = now();
        $rows = [];

        foreach (range(1, $count) as $index) {
            $first = $firstNames[($index - 1 + $firstOffset) % count($firstNames)];
            $last = $surnames[($index - 1 + $lastOffset) % count($surnames)];

            $rows[] = [
                'company_id' => $company->id,
                'employee_code' => sprintf('%s%03d', $code, $index),
                'name' => "{$first} {$last}",
                'email' => $this->addressFor($first, $last),
                'attendance_source' => 'manual',
                // Two of each are deliberately out, so the base count never
                // trivially equals the headcount.
                'is_meal_eligible' => $index !== $count - 1,
                'status' => $index === $count ? 'inactive' : 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // One insert per company rather than one per employee.
        Employee::insert($rows);
    }

    /**
     * A person's own address, numbered only when the plain one is taken - the
     * same thing a real company ends up doing.
     */
    protected function addressFor(string $first, string $last): string
    {
        $base = strtolower($first.'.'.$last);
        $address = $base.'@'.self::DOMAIN;
        $seen = 1;

        while (User::where('email', $address)->exists() || Employee::where('email', $address)->exists()) {
            $seen++;
            $address = $base.$seen.'@'.self::DOMAIN;
        }

        return $address;
    }

    /**
     * Enough movement that the screens are not empty: skips on the next meal
     * day from the sources a company actually produces, and a locked count on
     * the last one so the reports and the vendor's view have a past.
     */
    protected function seedActivity(Company $company, User $admin): void
    {
        $employees = Employee::where('company_id', $company->id)
            ->where('status', 'active')
            ->where('is_meal_eligible', true)
            ->orderBy('id')
            ->get();

        $next = $this->nextMealDay();
        $last = $this->previousMealDay();

        $reasons = [
            'leave' => 'Family function',
            'wfh' => 'Working from home today',
            'hr' => 'Out of station for a client meeting',
            'self' => 'Doctor appointment',
        ];

        $offset = 0;

        foreach ($reasons as $source => $reason) {
            if (! $employee = $employees[$offset++] ?? null) {
                continue;
            }

            Skip::create([
                'company_id' => $company->id,
                'employee_id' => $employee->id,
                'date' => $next,
                'source' => $source,
                'reason' => $reason,
                'created_by' => $admin->id,
            ]);
        }

        if ($employees->first()) {
            Skip::create([
                'company_id' => $company->id,
                'employee_id' => $employees->first()->id,
                'date' => $last,
                'source' => 'leave',
                'reason' => 'Travelling for work',
                'created_by' => $admin->id,
            ]);
        }

        $numbers = (new CalculateExpectedMeals)->execute($company, $last);

        MealCount::create([
            'company_id' => $company->id,
            'tiffin_service_id' => $company->activeAssignment->tiffin_service_id,
            'date' => $last,
            'base_eligible_count' => $numbers['base_eligible_count'],
            'skip_count' => $numbers['skip_count'],
            'extra_count' => $numbers['extra_count'],
            'final_expected_count' => $numbers['final_expected_count'],
            'breakdown' => $numbers['breakdown'],
            'status' => 'auto_confirmed',
            'lock_type' => 'auto',
            'locked_at' => Carbon::parse($last, $this->timezone)->setTime(11, 0),
            'summary_sent_at' => Carbon::parse($last, $this->timezone)->setTime(10, 30),
            'reviewed_by' => $admin->id,
            'reviewed_at' => Carbon::parse($last, $this->timezone)->setTime(10, 40),
        ]);
    }

    protected function seedMenu(TiffinService $kitchen): void
    {
        $meals = [
            'Monday' => 'Pav Bhaji, Onion Salad, Shrikhand',
            'Tuesday' => 'Masoor Dal, Bhindi Fry, Roti, Rice',
            'Wednesday' => 'Veg Pulao, Dahi Kadhi, Papad',
            'Thursday' => 'Matar Paneer, Tawa Roti, Boondi Raita',
            'Friday' => 'Sambar Rice, Beans Poriyal, Rasam, Payasam',
        ];

        // This week and next, so the vendor view and the employee's next seven
        // days both have something to show.
        foreach ([0, 1] as $week) {
            $menu = WeeklyMenu::create([
                'tiffin_service_id' => $kitchen->id,
                'week_start_date' => Carbon::today($this->timezone)->startOfWeek()->addWeeks($week)->toDateString(),
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

    protected function nextMealDay(): string
    {
        $date = Carbon::today($this->timezone)->addDay();

        while ($date->isWeekend()) {
            $date->addDay();
        }

        return $date->toDateString();
    }

    protected function previousMealDay(): string
    {
        $date = Carbon::today($this->timezone)->subDay();

        while ($date->isWeekend()) {
            $date->subDay();
        }

        return $date->toDateString();
    }

    protected function report(): void
    {
        $this->newLine();
        $this->info(sprintf(
            'Added %d kitchens and %d companies, %d employees in all.',
            count(self::KITCHENS),
            count(self::COMPANIES),
            Employee::where('email', 'like', '%@'.self::DOMAIN)->count(),
        ));
        $this->newLine();

        $this->table(
            ['Role', 'Organisation', 'Sign in with'],
            array_merge(
                array_map(
                    fn (array $k) => ['Kitchen', $k[0], $k[1].'@'.self::DOMAIN],
                    self::KITCHENS,
                ),
                array_map(
                    fn (array $c) => ['Company admin', $c[0], 'hr.'.strtolower($c[1]).'@'.self::DOMAIN],
                    self::COMPANIES,
                ),
            ),
        );

        $this->line('Password for all of them: '.self::PASSWORD);
        $this->line('Employees sign in as firstname.lastname@'.self::DOMAIN.' - read one off a roster screen.');
        $this->newLine();
        $this->line('To take all of it out again, with nothing else touched:');
        $this->line('  php artisan mealbells:sample-orgs --remove');
        $this->newLine();
    }
}
