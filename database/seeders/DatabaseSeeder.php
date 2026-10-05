<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\CompanyTiffinAssignment;
use App\Models\TiffinService;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }

        // 1. Create a Tiffin Service
        $tiffin = TiffinService::create([
            'name' => 'Annapurna Gourmet Tiffin',
            'address' => '123 Kitchen Street, Food City',
            'contact_phone' => '+1 555-0199',
        ]);

        // 2. Create a Company
        $company = Company::create([
            'name' => 'TechCorp Solutions',
            'address' => '456 Innovation Way, Tech Park',
            'contact_phone' => '+1 555-0288',
        ]);

        // 3. Assign Company to Tiffin Service
        CompanyTiffinAssignment::create([
            'company_id' => $company->id,
            'tiffin_service_id' => $tiffin->id,
            'is_active' => true,
            'assigned_at' => now(),
        ]);

        // 4. Create Super Admin User
        User::create([
            'name' => 'Super Admin',
            'email' => 'admin@mealbells.com',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
        ]);

        // 5. Create Tiffin Admin User
        User::create([
            'name' => 'Chef Rahul (Tiffin Admin)',
            'email' => 'tiffin@mealbells.com',
            'password' => Hash::make('password'),
            'role' => 'tiffin_admin',
            'tiffin_service_id' => $tiffin->id,
        ]);

        // 6. Create Company Admin User
        User::create([
            'name' => 'Priya Sharma (Company Admin)',
            'email' => 'company@mealbells.com',
            'password' => Hash::make('password'),
            'role' => 'company_admin',
            'company_id' => $company->id,
        ]);
    }
}
