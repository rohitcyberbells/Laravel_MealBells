<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether this company's attendance is pulled at all.
 *
 * Default off, following wfh_auto_skip: a company opts in deliberately, and
 * every existing company keeps behaving exactly as it did.
 *
 * In shadow mode this only decides whether the report has anything to say. It
 * is introduced now rather than later so that turning the feature on is one
 * switch in one place, and so the per-employee half
 * (employees.attendance_source = 'integrated') has a company-level counterpart
 * to be ANDed with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->boolean('attendance_absence_enabled')->default(false)->after('wfh_auto_skip');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('attendance_absence_enabled');
        });
    }
};
