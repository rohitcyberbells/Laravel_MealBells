<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the queries that run on every page load.
 *
 * CalculateExpectedMeals is the hot path - it runs for today on every
 * dashboard, daily and vendor view, and seven more times for the forecast
 * strip. EXPLAIN showed it reading more than it needed:
 *
 *   skips by company+date    SEARCH using (company_id, external_ref) - only the
 *                            company_id prefix helped, so it walked every skip
 *                            that company has ever had and filtered by date
 *   meal_adjustments         SCAN - a full table scan, every time
 *   users by company+role    SCAN - also on every HRMS actor resolution
 *   users by company+login   SCAN - on every employee sign-in by code
 *
 * These are plain composite indexes, so they behave the same on SQLite,
 * PostgreSQL and MySQL. Nothing about the schema changes, only how it is read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skips', function (Blueprint $table) {
            // Deliberately (company_id, date) and not the reverse: every query
            // is scoped to one company first.
            $table->index(['company_id', 'date'], 'skips_company_id_date_index');
        });

        Schema::table('meal_adjustments', function (Blueprint $table) {
            $table->index(['company_id', 'date'], 'meal_adjustments_company_id_date_index');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index(['company_id', 'role'], 'users_company_id_role_index');

            // Sign-in by employee code, which is the only way an employee
            // without an email address can get in.
            $table->index(['company_id', 'login_code'], 'users_company_id_login_code_index');
        });
    }

    public function down(): void
    {
        Schema::table('skips', function (Blueprint $table) {
            $table->dropIndex('skips_company_id_date_index');
        });

        Schema::table('meal_adjustments', function (Blueprint $table) {
            $table->dropIndex('meal_adjustments_company_id_date_index');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_company_id_role_index');
            $table->dropIndex('users_company_id_login_code_index');
        });
    }
};
