<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned. One line of the Annual Work Plan & Budget — the
        // manual's `no | activity | owner | month × week` row, plus the budget
        // line it is costed against and the output indicator it delivers.
        //
        // indicator_id IS NULLABLE AND THAT IS THE POINT. The manual's rule is
        // that every activity carries an output indicator; a NOT NULL column
        // would enforce it by making half-drafted plans unsaveable, which in
        // practice produces a fake indicator per row and destroys the signal.
        // Nullable + a visible warning on every screen that shows the
        // activity keeps the rule legible and the breach countable. The
        // instance may promote the warning to a submission block via the
        // `workplans.require_output_indicator` setting.
        Schema::create('workplan_activities', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            // CASCADE, deliberately, against the restrict-by-default rule: an
            // activity has no existence apart from its plan, and both are soft
            // -deleted, so this constraint only ever fires on a genuine hard
            // delete (a test teardown, a mistaken tenant purge) where leaving
            // orphan rows behind would be worse than removing them.
            $table->foreignId('workplan_id')->constrained()->cascadeOnDelete();
            // Optional links: a plan line may be a project's milestone, a
            // programme activity with no project, or both.
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('indicator_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(0);   // the manual's `no` column
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('responsible_unit')->nullable();         // "Planning & Budget Dept"
            $table->string('schedule_granularity', 8)->default('month'); // ActivityScheduleGranularity
            // Real dates at both granularities — see the enum's note. The
            // Gantt derives its columns from these; nothing stores "month 4".
            $table->date('planned_start');
            $table->date('planned_end');
            $table->date('actual_start')->nullable();
            $table->date('actual_end')->nullable();
            $table->string('budget_line', 60)->nullable();          // vote / economic code
            $table->decimal('budget_amount', 18, 2)->default(0);
            $table->decimal('expenditure_to_date', 18, 2)->default(0);
            // The roll-up weight (App\Support\WorkplanProgress). Default 1 =
            // an unweighted mean; raise it for the lines that carry the year.
            $table->unsignedSmallInteger('weight')->default(1);
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->string('status', 12)->default('not_started');   // ActivityStatus
            // The Gantt's dependency arrow. Self-referencing and nullable:
            // nullOnDelete rather than cascade, because losing a predecessor
            // must not silently delete everything downstream of it.
            $table->foreignId('depends_on_id')->nullable()
                ->constrained('workplan_activities')->nullOnDelete();
            $table->timestamp('progress_recorded_at')->nullable();
            // The idempotence gate of the overdue sweep — null-checked and
            // advanced under the same row lock as the dispatch, exactly as
            // report_obligations.overdue_notified_at is.
            $table->timestamp('overdue_notified_at')->nullable();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'workplan_id', 'position']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'planned_end']);
            $table->index(['tenant_id', 'indicator_id']);
        });
    }

    public function down(): void
    {
        Schema::table('workplan_activities', function (Blueprint $table) {
            // By column, never by name — the form SQLite tolerates. The self
            // -reference has to go before the table can be dropped on MySQL.
            $table->dropForeign(['depends_on_id']);
        });

        Schema::dropIfExists('workplan_activities');
    }
};
