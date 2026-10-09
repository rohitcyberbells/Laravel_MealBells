<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the HR system said about who turned up, for one employee on one day.
 *
 * Deliberately separate from `skips`. In shadow mode there is no skip to create
 * - the whole point is that the count does not move - and this table is what
 * the expected-versus-actual report keeps reading afterwards, whether or not
 * attendance is ever allowed to affect a count.
 *
 * A boolean, not a timestamp. We do not need to know when somebody arrived,
 * only whether they had by the cutoff: that judgement is made once, against the
 * company's cutoff in its own timezone, and the arrival time is discarded. It
 * keeps attendance-surveillance data out of MealBells altogether, so a leaked
 * copy of this database says nothing about anyone's movements.
 *
 * See docs/attendance-design.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('date');

            /*
             * Null means the HR system had no answer for this person - not
             * matched, or unreadable. It is not the same as false, and the
             * report must never treat it as an absence.
             */
            $table->boolean('clocked_in_by_cutoff')->nullable();

            /*
             * They clocked in, from home. Kept because such a person should be
             * attributed to work-from-home rather than counted as absent, and
             * only the attendance record knows which it was.
             */
            $table->boolean('is_wfh')->default(false);

            $table->string('source')->default('cyberpulse');

            $table->timestamps();

            // One answer per employee per day. A second pull on the same day
            // updates the row rather than adding to it, which is what makes a
            // repeated run idempotent.
            $table->unique(['employee_id', 'date']);

            // The report reads a company's day, which is the only access path.
            $table->index(['company_id', 'date'], 'attendance_days_company_id_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_days');
    }
};
