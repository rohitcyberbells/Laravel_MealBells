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
        Schema::table('company_settings', function (Blueprint $table) {
            $table->json('meal_days')->nullable()->after('wfh_auto_skip');
        });

        // Set default for existing records
        DB::table('company_settings')
            ->whereNull('meal_days')
            ->update(['meal_days' => json_encode([1, 2, 3, 4, 5])]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('meal_days');
        });
    }
};
