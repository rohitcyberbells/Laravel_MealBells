<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('companies')->cascadeOnDelete();
            $table->time('cutoff_time')->default('10:30:00');
            $table->string('timezone')->default('Asia/Kolkata');
            $table->boolean('wfh_auto_skip')->default(false);
            $table->foreignId('primary_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('backup_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};
