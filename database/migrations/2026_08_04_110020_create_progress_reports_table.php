<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned (progress-reporting.md §1.3). The reported FACT for one
        // project in one window: what was done, what it cost this period, and
        // what physical progress is claimed.
        //
        // The draft row IS the autosave target — SaveProgressReportDraft
        // updates it and stamps autosaved_at. No JSON payload column, no
        // second storage shape for the same data.
        //
        // ONE LIVE REPORT per (project, period) is enforced in
        // StartProgressReport with lockForUpdate, NOT by a DB unique: soft
        // deletes make unique(project_id, reporting_period_id) either block
        // re-creation after a discard or — with deleted_at in the key —
        // enforce nothing.
        Schema::create('progress_reports', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('reporting_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('report_obligation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 12)->default('draft');     // ProgressReportStatus
            $table->text('narrative_work_done');                // work done vs timeline
            $table->text('narrative_challenges')->nullable();   // challenges…
            $table->text('narrative_mitigation')->nullable();   // …and mitigation (the manual pairs them)
            $table->text('narrative_next_period')->nullable();
            $table->decimal('physical_progress_claimed', 5, 2);            // cumulative claim
            $table->decimal('physical_progress_before', 5, 2)->nullable(); // project value at approval (audit)
            $table->text('progress_decrease_reason')->nullable();          // required if the claim is below current
            // MONEY SPLIT, deliberately: period_expenditure is the reported
            // fact; the project total is the roll-up; the snapshot is written
            // at approval for audit. Storing a claimed cumulative AND summing
            // the periods would give two answers to one question.
            $table->decimal('period_expenditure', 18, 2)->default(0);
            $table->decimal('cumulative_expenditure_snapshot', 18, 2)->nullable();
            $table->string('entry_mode', 12)->default('self_service');     // ReportEntryMode
            // Whose figures these are, when an officer typed them.
            $table->foreignId('contractor_id')->nullable()->constrained()->nullOnDelete();
            // Snapshotted from the obligation so a later calendar edit cannot
            // retroactively make a filed report late.
            $table->timestamp('due_at');
            $table->boolean('submitted_late')->default(false);
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('returned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->timestamp('autosaved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'reporting_period_id', 'status']);
            $table->index(['tenant_id', 'project_id', 'reporting_period_id']);
            $table->index(['tenant_id', 'due_at']);
        });

        // The back-reference completed. report_obligations.progress_report_id
        // was created without its constraint because the two tables point at
        // each other; adding it here breaks the circular dependency without a
        // third migration. SQLite compiles no statement for a foreign key on
        // an existing table (it has none to compile), so this is a no-op on
        // the test connection and a real constraint on MySQL.
        Schema::table('report_obligations', function (Blueprint $table) {
            $table->foreign('progress_report_id')
                ->references('id')->on('progress_reports')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('report_obligations', function (Blueprint $table) {
            // By column, never by name: dropForeign(['column']) is the form
            // SQLite tolerates.
            $table->dropForeign(['progress_report_id']);
        });

        Schema::dropIfExists('progress_reports');
    }
};
