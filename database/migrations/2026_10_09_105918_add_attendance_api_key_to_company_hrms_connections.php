<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The key the attendance endpoint is read with.
 *
 * Separate from the pull credentials, and deliberately not a login. The leave
 * fetch signs in as a real CyberPulse employee; the attendance endpoint is
 * keyed, so MealBells never holds a person's password in order to read whether
 * their colleagues turned up, and no service account appears in the vendor's
 * own attendance reports as a member of staff.
 *
 * Encrypted at rest like every other HRMS credential: a leaked dump must not
 * hand over the ability to read a company's attendance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_hrms_connections', function (Blueprint $table) {
            $table->text('attendance_api_key')->nullable()->after('pull_token_expires_at');
            $table->timestamp('last_attendance_pull_at')->nullable()->after('attendance_api_key');
            $table->string('last_attendance_pull_status')->nullable()->after('last_attendance_pull_at');
            $table->text('last_attendance_pull_error')->nullable()->after('last_attendance_pull_status');
            $table->json('last_attendance_pull_summary')->nullable()->after('last_attendance_pull_error');
        });
    }

    public function down(): void
    {
        Schema::table('company_hrms_connections', function (Blueprint $table) {
            $table->dropColumn([
                'attendance_api_key',
                'last_attendance_pull_at',
                'last_attendance_pull_status',
                'last_attendance_pull_error',
                'last_attendance_pull_summary',
            ]);
        });
    }
};
