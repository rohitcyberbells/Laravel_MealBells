<?php

namespace Database\Seeders;

use App\Actions\Meal\CalculateExpectedMeals;
use App\Models\Company;
use App\Models\CompanyCalendarDay;
use App\Models\CompanyHrmsConnection;
use App\Models\CompanySetting;
use App\Models\CompanyTiffinAssignment;
use App\Models\DailyOverrides;
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
 * A full, walkable demo.
 *
 * Acme and Northwind are the two companies a demo is actually walked through:
 * skips from every source, a locked day with a late change on it, a holiday,
 * guest meals, an emergency menu override, and an HR system connected to one of
 * them and not the other. Every row printLogins() prints is one of these.
 *
 * Around them sits the rest of a plausible platform - five kitchens, one of them
 * paired with nobody, and twenty companies between them - so the super admin's
 * directory, the health screen and the reports are not a product with two
 * customers. Those companies are scenery: lighter activity, and no login rows.
 *
 * Dates are derived from today rather than hardcoded, so the demo still reads
 * correctly whenever it is run.
 */
class DemoSeeder extends Seeder
{
    protected const PASSWORD = 'demo1234';

    /** Known so a demo can sign a webhook by hand or with hrms:simulate. */
    protected const HRMS_SECRET = 'whsec_demo_acme_0123456789';

    /**
     * Every demo account is on one domain, so a credential is recognisable as
     * ours at a glance and nothing in a demo can be mistaken for a real inbox.
     */
    protected const DOMAIN = 'bells.com';

    /**
     * Three more kitchens, so twenty companies are not all cooked for by one.
     *
     * @var array<int, array{0: string, 1: string, 2: string, 3: string}>
     */
    protected const EXTRA_KITCHENS = [
        ['Sharda Kitchen', 'Shop 7, Mill Road, Kothrud, Pune 411038', '9823104477', 'sharda'],
        ['Gokul Bhojnalaya', '91 Station Road, Shivajinagar, Pune 411005', '8806621390', 'gokul'],
        ['Satkar Caterers', 'Unit 3, Canal Street, Yerwada, Pune 411006', '9850337214', 'satkar'],
    ];

    /**
     * Companies beyond the two the demo is walked through, so the super admin's
     * directory, the health screen and the reports hold a believable number of
     * rows rather than two.
     *
     * Headcount, address and phone all differ per row. They used to share one
     * address and one phone number across all twenty, which is the single
     * fastest way for anybody being shown this to see it is made up - a
     * directory where every company is at 440 Business Park is not a directory.
     *
     * @var array<int, array{0: string, 1: string, 2: int, 3: string, 4: string}>
     */
    protected const SCALE_COMPANIES = [
        ['Meridian Softworks', 'MERI', 46, 'Tower B, Cerebrum IT Park, Kalyani Nagar, Pune 411014', '9822017634'],
        ['Kaveri Textiles', 'KAVE', 62, 'Plot 14, MIDC Bhosari, Pune 411026', '8605443120'],
        ['Sunrise Logistics', 'SUNR', 24, 'Warehouse 9, Talawade Road, Pune 412114', '9890226581'],
        ['Blue Orbit Analytics', 'BLOR', 18, '5th Floor, Panchshil Tech Park, Hinjewadi, Pune 411057', '9765518042'],
        ['Deccan Pharma', 'DECC', 55, 'Plot 22, Pharma Zone, Chakan MIDC, Pune 410501', '8975330916'],
        ['Pinnacle Realty', 'PINN', 14, '2nd Floor, Amar Paradigm, Baner Road, Pune 411045', '9823778105'],
        ['Vayu Aerospace', 'VAYU', 31, 'Hangar 4, Lohegaon Industrial Area, Pune 411032', '8888204763'],
        ['Nalanda EdTech', 'NALA', 27, '301 Sanas Plaza, Viman Nagar, Pune 411014', '9028614477'],
        ['Greenfield Agritech', 'GREE', 38, 'Survey 118, Wagholi, Pune 412207', '9850071263'],
        ['Trident Engineering', 'TRID', 44, 'Gate 3, Pimpri Industrial Estate, Pune 411018', '8793352618'],
        ['Saffron Hospitality', 'SAFF', 21, 'Nagar Road, Kharadi, Pune 411014', '9767440852'],
        ['Zenith Bearings', 'ZENI', 36, 'Block C, Chinchwad MIDC, Pune 411019', '9890553741'],
        ['Chinar Finserv', 'CHIN', 29, '7th Floor, Marvel Edge, Viman Nagar, Pune 411014', '8956127403'],
        ['Konkan Seafoods', 'KONK', 17, 'Cold Store 2, Market Yard, Gultekdi, Pune 411037', '9822649170'],
        ['Aether Labs', 'AETH', 12, '12 Bavdhan Tech Hub, Bavdhan, Pune 411021', '9604338215'],
        ['Rangoli Media', 'RANG', 23, 'Studio 6, FC Road, Shivajinagar, Pune 411004', '8237905614'],
        ['Indus Cement', 'INDU', 51, 'Depot Road, Katraj, Pune 411046', '9823440278'],
        ['Prayag Insurance', 'PRAY', 33, '4th Floor, Nucleus Mall, Camp, Pune 411001', '9765082336'],
    ];

    protected string $timezone = 'Asia/Kolkata';

    /**
     * Every demo account shares one password, so it is hashed once.
     *
     * Hashed per user, bcrypt was most of this seeder's running time - at
     * twenty companies that is over a hundred hashes of one known string.
     */
    protected string $passwordHash = '';

    /** @var array<int, array{role: string, company: string, login: string, password: string, note: string}> */
    protected array $logins = [];

    /**
     * Addresses handed out so far, so no two employees get the same one.
     *
     * @var array<string, true>
     */
    protected array $takenAddresses = [];

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            $this->command?->error('DemoSeeder only runs in local or testing.');

            return;
        }

        $this->passwordHash = Hash::make(self::PASSWORD);

        $tiffin = $this->seedVendor();

        $acme = $this->seedCompany(
            'Acme Industries', 'ACME', $tiffin,
            employeeCount: 30, cutoff: '11:00:00',
            address: 'Unit 4, Magarpatta Cybercity, Hadapsar, Pune 411028',
            phone: '9822140357',
            featured: true,
        );

        $northwind = $this->seedCompany(
            'Northwind Traders', 'NWND', $tiffin,
            employeeCount: 10, cutoff: '10:30:00',
            address: '2nd Floor, Trade Centre, Aundh, Pune 411007',
            phone: '8806094512',
            featured: true,
        );

        $this->seedWeeklyMenu($tiffin);
        $this->seedDailyOverride($tiffin);

        $this->seedActivity($acme);
        $this->seedActivity($northwind, lighter: true);

        // Only Acme is wired to an HR system, so the connect screen has
        // something to show while Northwind demonstrates the unconnected state.
        $this->seedHrmsConnection($acme);

        $this->seedUnpairedVendor();
        $this->seedScale($tiffin);

        $this->printLogins();
    }

    protected function seedVendor(): TiffinService
    {
        $tiffin = TiffinService::create([
            'name' => 'Annapurna Gourmet Tiffin',
            'address' => 'Kitchen 12, Shankar Sheth Road, Swargate, Pune 411042',
            'contact_phone' => '9822015580',
        ]);

        User::create([
            'name' => 'Platform Root',
            'email' => 'admin@'.self::DOMAIN,
            'password' => $this->passwordHash,
            'role' => 'super_admin',
            'must_change_password' => false,
        ]);

        $this->logins[] = [
            'role' => 'super_admin', 'company' => '—',
            'login' => 'admin@'.self::DOMAIN, 'password' => self::PASSWORD,
            'note' => 'Pairing, health, HRMS secrets',
        ];

        User::create([
            'name' => 'Chef Rahul',
            'email' => 'annapurna@'.self::DOMAIN,
            'password' => $this->passwordHash,
            'role' => 'tiffin_admin',
            'tiffin_service_id' => $tiffin->id,
            'must_change_password' => false,
        ]);

        $this->logins[] = [
            'role' => 'tiffin_admin', 'company' => 'Annapurna',
            'login' => 'annapurna@'.self::DOMAIN, 'password' => self::PASSWORD,
            'note' => 'Weekly menu, preparation view',
        ];

        return $tiffin;
    }

    /**
     * A second kitchen, deliberately paired with nobody.
     *
     * Two things were unreachable in a demo with one kitchen. The super admin's
     * pairing screen offered a single choice, so the decision it exists for
     * looked like a formality. And the vendor's own "this kitchen is not
     * connected to a company yet" screen - what every real vendor sees on their
     * first day - could not be shown at all.
     *
     * It stays unpaired on purpose. Pairing it during a demo is itself the
     * thing worth demonstrating, and it can be undone by re-running the build.
     */
    protected function seedUnpairedVendor(): void
    {
        $tulsi = TiffinService::create([
            'name' => 'Tulsi Rasoi',
            'address' => '44 Market Road, Hadapsar, Pune 411028',
            'contact_phone' => '8806712945',
        ]);

        User::create([
            'name' => 'Chef Meera',
            'email' => 'tulsi@'.self::DOMAIN,
            'password' => $this->passwordHash,
            'role' => 'tiffin_admin',
            'tiffin_service_id' => $tulsi->id,
            'must_change_password' => false,
        ]);

        $this->logins[] = [
            'role' => 'tiffin_admin', 'company' => 'Tulsi Rasoi',
            'login' => 'tulsi@'.self::DOMAIN, 'password' => self::PASSWORD,
            'note' => 'Unpaired: shows a new vendor\'s first-day screens',
        ];
    }

    /**
     * @param  bool  $featured  Whether this company's logins go in the printed
     *                          table. Twenty companies produce over a hundred
     *                          rows, which is not a list anybody can use.
     */
    protected function seedCompany(string $name, string $code, TiffinService $tiffin, int $employeeCount, string $cutoff, string $address, string $phone, bool $featured = false): Company
    {
        $company = Company::create([
            'name' => $name,
            'code' => $code.'01',
            'address' => $address,
            'contact_phone' => $phone,
        ]);

        $slug = strtolower($code);
        $domain = self::DOMAIN;

        $primaryAdmin = User::create([
            'name' => "{$name} HR",
            'email' => "hr.{$slug}@{$domain}",
            'password' => $this->passwordHash,
            'role' => 'company_admin',
            'company_id' => $company->id,
            'must_change_password' => false,
        ]);

        $backupAdmin = User::create([
            'name' => "{$name} HR Backup",
            'email' => "hr.{$slug}.backup@{$domain}",
            'password' => $this->passwordHash,
            'role' => 'company_admin',
            'company_id' => $company->id,
            'must_change_password' => false,
        ]);

        if ($featured) {
            $this->logins[] = [
                'role' => 'company_admin', 'company' => $name,
                'login' => "hr.{$slug}@{$domain}", 'password' => self::PASSWORD,
                'note' => 'Primary admin (gets the daily summary)',
            ];

            $this->logins[] = [
                'role' => 'company_admin', 'company' => $name,
                'login' => "hr.{$slug}.backup@{$domain}", 'password' => self::PASSWORD,
                'note' => 'Backup admin (gets anomaly escalations)',
            ];
        }

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

        $this->seedEmployees($company, $code, $employeeCount, $featured);

        return $company;
    }

    protected function seedEmployees(Company $company, string $code, int $count, bool $featured = false): void
    {
        $firstNames = ['Aarav', 'Diya', 'Vivaan', 'Ananya', 'Arjun', 'Isha', 'Kabir', 'Meera', 'Rohan', 'Saanvi',
            'Advait', 'Kiara', 'Reyansh', 'Myra', 'Ayaan', 'Aadhya', 'Krishna', 'Anika', 'Ishaan', 'Navya',
            'Dhruv', 'Riya', 'Atharv', 'Pari', 'Shaurya', 'Avni', 'Veer', 'Tara', 'Nikhil', 'Sara'];

        // Names used to be the first name plus the company code - "Aarav ACME".
        // On a roster of sixty that reads as test data at a glance, which is
        // the one thing demo data must not do. The two lists are coprime in
        // length, so a surname does not repeat with the same first name until
        // well past any company's headcount.
        $surnames = ['Sharma', 'Iyer', 'Patil', 'Nair', 'Deshmukh', 'Rao', 'Kulkarni', 'Banerjee', 'Gupta',
            'Chauhan', 'Menon', 'Joshi', 'Reddy', 'Pillai', 'Shetty', 'Verma', 'Mehta', 'Bhatt', 'Kaur',
            'Saxena', 'Naik', 'Dubey', 'Sinha', 'Trivedi', 'Jain', 'Ghosh', 'Pandey', 'Bose', 'Chopra',
            'Mishra', 'Agarwal', 'Thakur', 'Rane', 'Salunkhe', 'Mahajan', 'Bhosale', 'Chavan', 'Gokhale',
            'Kamat', 'Dalvi', 'Phadke', 'Sawant', 'Borkar', 'Kadam', 'Gaikwad', 'Prabhu', 'Hegde'];

        $now = now();
        $rows = [];

        /** @var array<int, array{code: string, name: string, email: string, index: int}> */
        $wantLogins = [];

        /*
         * Each company starts at its own place in both lists, so Acme and
         * Kaveri do not both open with Aarav Sharma, Diya Iyer, Vivaan Patil.
         * The lists are 30 and 31 long - coprime - so within one company a
         * first name and surname never pair up twice.
         */
        $firstOffset = crc32($code) % count($firstNames);
        $lastOffset = crc32(strrev($code)) % count($surnames);

        foreach (range(1, $count) as $index) {
            $employeeCode = sprintf('%s%03d', $code, $index);
            $first = $firstNames[($index - 1 + $firstOffset) % count($firstNames)];
            $last = $surnames[($index - 1 + $lastOffset) % count($surnames)];
            $name = "{$first} {$last}";

            // A couple of each company are deliberately not eligible, so the
            // base count never trivially equals the headcount.
            $isInactive = $index === $count;
            $isIneligible = $index === $count - 1;

            // One employee with no address, so the demo covers the roster notice
            // that names who could not be given a login and why.
            $email = $index === 2 ? null : $this->addressFor($first, $last);

            $rows[] = [
                'company_id' => $company->id,
                'employee_code' => $employeeCode,
                // The HR system's own reference, so the connect screen can
                // suggest one and hrms:simulate has something to aim at.
                'external_id' => "HR-{$employeeCode}",
                'name' => $name,
                'email' => $email,
                'attendance_source' => 'manual',
                'is_meal_eligible' => ! $isIneligible,
                'status' => $isInactive ? 'inactive' : 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ];

            /*
             * Logins for the first three of a featured company and the first of
             * every other - minus the one with no address, who gets none. That
             * is not a gap in the demo, it is the demo: sign-in is by email, so
             * an employee without one cannot be given an account, and the roster
             * screen says so.
             */
            if ($email && ($featured ? $index <= 3 : $index === 1)) {
                $wantLogins[] = ['code' => $employeeCode, 'name' => $name, 'email' => $email, 'index' => $index];
            }
        }

        /*
         * One insert per company rather than one per employee.
         *
         * At twenty companies this is over six hundred rows, and created one at
         * a time it made DemoSeederTest - which reseeds for every test - roughly
         * seven times slower than the rest of the suite put together. Employee
         * has no 'created' hooks, and its observer only listens for 'updated',
         * so nothing is skipped by going round the model here. The defaults the
         * model would have applied are written out above instead.
         */
        Employee::insert($rows);

        foreach ($wantLogins as $wanted) {
            $user = User::create([
                'name' => $wanted['name'],
                'email' => $wanted['email'],
                'password' => $this->passwordHash,
                'role' => 'employee',
                'company_id' => $company->id,
                'login_code' => $wanted['code'],
                // False on purpose: true would drop every demo login onto
                // the change-password screen before anything can be shown.
                'must_change_password' => false,
            ]);

            Employee::where('company_id', $company->id)
                ->where('employee_code', $wanted['code'])
                ->update(['user_id' => $user->id]);

            if ($featured) {
                $this->logins[] = [
                    'role' => 'employee', 'company' => $company->name,
                    'login' => $wanted['email'],
                    'password' => self::PASSWORD,
                    'note' => $wanted['index'] === 1 ? 'Has a recurring Monday skip' : 'Employee portal',
                ];
            }
        }
    }

    /**
     * A person's own address, rather than their employee code.
     *
     * Employees used to be acme001@demo.test. It is unique for free and it
     * reads as a row out of a database, which is the one thing data being
     * shown to somebody must not do.
     *
     * Six hundred people drawn from thirty first names and thirty-one surnames
     * will collide, so the first duplicate keeps the plain address and the rest
     * take a number - which is what a real company does too.
     */
    protected function addressFor(string $first, string $last): string
    {
        $base = strtolower($first.'.'.$last);
        $address = $base.'@'.self::DOMAIN;

        $seen = 1;

        while (isset($this->takenAddresses[$address])) {
            $seen++;
            $address = $base.$seen.'@'.self::DOMAIN;
        }

        $this->takenAddresses[$address] = true;

        return $address;
    }

    /**
     * @param  array<string, string>|null  $meals  Defaults to Annapurna's week.
     *                                             Kitchens serving an identical
     *                                             menu is the sort of thing that
     *                                             gets noticed mid-demo.
     */
    protected function seedWeeklyMenu(TiffinService $tiffin, ?array $meals = null): void
    {
        $meals ??= [
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

    /**
     * One emergency override, on today.
     *
     * Four screens read this - the vendor's dashboard and preparation page, the
     * company admin's dashboard and the employee's next seven days - and until
     * now the demo seeded none, so on all four the override was dead UI nobody
     * demonstrating the product could ever reach. Today's date is deliberate:
     * the dashboards only look at today, so anything else shows on one screen.
     */
    protected function seedDailyOverride(TiffinService $tiffin): void
    {
        DailyOverrides::create([
            'tiffin_service_id' => $tiffin->id,
            'date' => Carbon::today($this->timezone)->toDateString(),
            'meal_description' => 'Khichdi, Kadhi, Papad, Mango Pickle',
            'reason' => 'Gas supply failed at 6am. A single-pot meal was the only way to cook for everyone before the vans left.',
        ]);
    }

    /**
     * The rest of the platform: three more kitchens and eighteen more companies.
     *
     * Two companies on one kitchen demonstrates the meal flow perfectly and the
     * platform not at all. The super admin's directory, the health screen and
     * every report read as a product with two customers, and the pairing screen
     * offers a choice of one. These exist to be a plausible denominator.
     *
     * They get lighter activity and no rows in the login table on purpose: they
     * are scenery, and a hundred and twenty logins is not a list anybody can
     * use. Their HR login follows the same pattern as everyone else's, which
     * printLogins() states once.
     */
    protected function seedScale(TiffinService $annapurna): void
    {
        $alternateMeals = [
            'Monday' => 'Pav Bhaji, Onion Salad, Shrikhand',
            'Tuesday' => 'Masoor Dal, Bhindi Fry, Roti, Rice',
            'Wednesday' => 'Veg Pulao, Dahi Kadhi, Papad',
            'Thursday' => 'Matar Paneer, Tawa Roti, Boondi Raita',
            'Friday' => 'Sambar Rice, Beans Poriyal, Rasam, Payasam',
        ];

        $kitchens = [$annapurna];

        foreach (self::EXTRA_KITCHENS as [$name, $address, $phone, $slug]) {
            $email = $slug.'@'.self::DOMAIN;

            $kitchen = TiffinService::create([
                'name' => $name,
                'address' => $address,
                'contact_phone' => $phone,
            ]);

            User::create([
                'name' => $name.' Admin',
                'email' => $email,
                'password' => $this->passwordHash,
                'role' => 'tiffin_admin',
                'tiffin_service_id' => $kitchen->id,
                'must_change_password' => false,
            ]);

            $this->logins[] = [
                'role' => 'tiffin_admin', 'company' => $name,
                'login' => $email, 'password' => self::PASSWORD,
                'note' => 'Another kitchen, with its own menu and companies',
            ];

            $this->seedWeeklyMenu($kitchen, $alternateMeals);

            $kitchens[] = $kitchen;
        }

        // Cutoffs differ per company, because a single shared cutoff makes the
        // scheduler look like it locks the whole platform at one moment.
        $cutoffs = ['10:00:00', '10:30:00', '11:00:00', '11:30:00', '12:00:00'];

        foreach (self::SCALE_COMPANIES as $index => [$name, $code, $headcount, $address, $phone]) {
            $company = $this->seedCompany(
                $name,
                $code,
                $kitchens[$index % count($kitchens)],
                employeeCount: $headcount,
                cutoff: $cutoffs[$index % count($cutoffs)],
                address: $address,
                phone: $phone,
            );

            $this->seedActivity($company, lighter: true);
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
        //
        // The reason is what a company admin reads on the roster, so each source
        // gets wording that source would plausibly produce - an HR entry does
        // not sound like an employee's own note. Saying "recorded for the demo"
        // put the word "demo" on screen during a demo.
        $sources = [
            'leave' => 'Family function',
            'wfh' => 'Working from home today',
            'hr' => 'Out of station for a client meeting',
            'self' => 'Doctor appointment',
        ];

        $offset = 0;

        foreach ($sources as $source => $reason) {
            $employee = $employees[$offset] ?? null;
            $offset++;

            if (! $employee) {
                continue;
            }

            Skip::create([
                'company_id' => $company->id,
                'employee_id' => $employee->id,
                'date' => $nextDay,
                'source' => $source,
                'reason' => $reason,
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
                'reason' => 'Plans changed',
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
                'reason' => 'Travelling for work',
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
                'reason' => 'Approved leave from the HR system',
            ],
        ];
    }

    protected function printLogins(): void
    {
        // getOutput() is null when a seeder is driven from a test rather than
        // the console, and newLine() below would fail on it. Output is the only
        // thing this method does, so there is nothing to do without it.
        if (! $this->command || ! $this->command->getOutput()) {
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
        $this->command->line('Everyone signs in with an email address. There is no employee-code route.');
        $this->command->newLine();

        // The other companies are deliberately not in the table above. Stating
        // the pattern is shorter than a hundred and twenty rows, and it is how
        // anybody reaches them if a demo needs to.
        $this->command->line(sprintf(
            'The other %d companies are not listed; their HR login follows the same pattern:',
            count(self::SCALE_COMPANIES),
        ));
        $this->command->line('  hr.<code>@'.self::DOMAIN.'   e.g. hr.meri@'.self::DOMAIN);
        $this->command->newLine();
        $this->command->line('Employees sign in with firstname.lastname@'.self::DOMAIN.'. Their');
        $this->command->line('addresses are on the roster screen of whichever company they work for.');
        $this->command->newLine();
        $this->command->line('Acme HRMS webhook secret: '.self::HRMS_SECRET);
        $this->command->line('Run `php artisan queue:work` for notifications, and `php artisan schedule:work` for the cutoff.');
        $this->command->newLine();
    }
}
