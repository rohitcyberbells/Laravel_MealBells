<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('meal_count_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_count_id')->constrained('meal_counts')->cascadeOnDelete();
            $table->integer('change_quantity'); // Positive or negative delta (e.g. +5, -3)
            $table->string('reason')->nullable();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meal_count_changes');
    }
};
