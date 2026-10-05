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
        Schema::create('weekly_menus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tiffin_service_id')->constrained('tiffin_services')->cascadeOnDelete();
            $table->date('week_start_date');
            $table->string('status')->default('draft'); // draft | published
            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('weekly_menus');
    }
};
