<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('employee_code');
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('attendance_source')->default('manual');
            $table->boolean('is_meal_eligible')->default(true);
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['company_id', 'employee_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
