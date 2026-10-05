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
        Schema::create('daily_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tiffin_service_id')->constrained('tiffin_services')->cascadeOnDelete();
            $table->date('date');
            $table->text('meal_description');
            $table->text('reason')->nullable();
            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_overrides');
    }
};
