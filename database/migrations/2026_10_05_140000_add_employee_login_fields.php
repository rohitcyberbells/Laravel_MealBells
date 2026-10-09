<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add code column to companies
        Schema::table('companies', function (Blueprint $table) {
            $table->string('code', 10)->nullable()->unique()->after('id');
        });

        // Populate code for existing companies
        $companies = DB::table('companies')->whereNull('code')->get();
        foreach ($companies as $comp) {
            $code = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $comp->name), 0, 4));
            if (strlen($code) < 3) {
                $code = 'CMP';
            }
            $code .= rand(10, 99);
            DB::table('companies')->where('id', $comp->id)->update(['code' => $code]);
        }

        // 2. Add login_code to users
        Schema::table('users', function (Blueprint $table) {
            $table->string('login_code', 50)->nullable()->after('company_id');
        });

        // 3. Add user_id FK to employees
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->unique()->after('company_id')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * The indexes are dropped before the columns they cover.
     *
     * PostgreSQL drops a dependent index along with its column, so this looked
     * fine for a long time. SQLite does not: it refuses with "error in index
     * employees_user_id_unique after drop column: no such column: user_id", so
     * a developer rolling back on their own machine hit a failure that never
     * happened on the deployed driver.
     *
     * Both columns were added with ->unique(), which is why both need it.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropUnique('employees_user_id_unique');
            $table->dropColumn('user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('login_code');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique('companies_code_unique');
            $table->dropColumn('code');
        });
    }
};
