<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Documented canonical values in config('mealbells.attendance_sources'): manual, integrated, none
        if (Schema::hasTable('employees') && Schema::hasColumn('employees', 'attendance_source')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->string('attendance_source')->default('manual')->change();
            });
        }
    }

    public function down(): void
    {
        // No-op
    }
};
