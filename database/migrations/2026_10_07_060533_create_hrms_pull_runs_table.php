<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * History of pull runs, one row each.
 *
 * A webhook integration has a row per event to look at when something goes
 * wrong. A pull has nothing comparable: the only evidence it worked is that it
 * ran. These rows are that evidence - what each run fetched, applied, cancelled
 * and refused, and why if it refused.
 *
 * Deliberately free of PII: counts, a status and a warning string. The per-leave
 * detail stays out, both because it is large and because it would reintroduce
 * employee identifiers into a table that does not need them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hrms_pull_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('adapter')->nullable();

            // 'ok' | 'suspicious' | 'failed'. 'suspicious' means the run finished
            // but a safety guard stopped it acting, so the data is stale rather
            // than wrong - a different problem from a failure.
            $table->string('status');
            $table->boolean('dry_run')->default(false);

            $table->unsignedInteger('fetched')->default(0);
            $table->unsignedInteger('applied')->default(0);
            $table->unsignedInteger('cancelled')->default(0);
            $table->unsignedInteger('ignored')->default(0);
            $table->unsignedInteger('unknown_employee')->default(0);
            $table->unsignedInteger('duplicate')->default(0);
            $table->unsignedInteger('cancel_candidates')->default(0);

            $table->json('warnings')->nullable();
            $table->text('error')->nullable();

            $table->timestamps();

            // The health page reads the newest run per company.
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hrms_pull_runs');
    }
};
