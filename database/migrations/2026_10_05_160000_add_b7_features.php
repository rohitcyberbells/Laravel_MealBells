<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add last_login_at to users
        if (! Schema::hasColumn('users', 'last_login_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('last_login_at')->nullable();
            });
        }

        // 2. Deactivate old duplicate active assignments (keep latest active per company)
        if (Schema::hasTable('company_tiffin_assignments')) {
            // havingRaw, not having('active_count', ...): PostgreSQL does not
            // allow a SELECT alias in HAVING, while SQLite and MySQL do. This
            // migration therefore failed outright on PostgreSQL - which is to
            // say a first deploy to the production driver never got past here.
            $duplicateCompanyIds = DB::table('company_tiffin_assignments')
                ->where('is_active', true)
                ->select('company_id')
                ->groupBy('company_id')
                ->havingRaw('COUNT(*) > 1')
                ->pluck('company_id');

            foreach ($duplicateCompanyIds as $companyId) {
                $latestId = DB::table('company_tiffin_assignments')
                    ->where('company_id', $companyId)
                    ->where('is_active', true)
                    ->max('id');

                DB::table('company_tiffin_assignments')
                    ->where('company_id', $companyId)
                    ->where('is_active', true)
                    ->where('id', '<', $latestId)
                    ->update([
                        'is_active' => false,
                        'unassigned_at' => now(),
                    ]);
            }
        }

        // 3. Add single active assignment constraint to company_tiffin_assignments
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS cta_active_company_unique ON company_tiffin_assignments (company_id) WHERE is_active = true');
        } else {
            if (! Schema::hasColumn('company_tiffin_assignments', 'active_company_id')) {
                Schema::table('company_tiffin_assignments', function (Blueprint $table) {
                    $table->unsignedBigInteger('active_company_id')
                        ->nullable()
                        ->storedAs('CASE WHEN is_active = 1 THEN company_id ELSE NULL END')
                        ->unique('cta_active_company_unique');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'last_login_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('last_login_at');
            });
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS cta_active_company_unique');
        } else {
            if (Schema::hasColumn('company_tiffin_assignments', 'active_company_id')) {
                Schema::table('company_tiffin_assignments', function (Blueprint $table) {
                    $table->dropUnique('cta_active_company_unique');
                    $table->dropColumn('active_company_id');
                });
            }
        }
    }
};
