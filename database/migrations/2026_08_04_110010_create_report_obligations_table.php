<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned. The materialized roll-up the league table reads
        // (progress-reporting.md §1.2).
        //
        // WHY MATERIALIZE "who hasn't reported" rather than derive it: the
        // standing question is how a state-wide board aggregates across 40
        // MDAs without scanning raw report rows, and this answers it with one
        // indexed GROUP BY. It also gives every reminder a durable per-row
        // stage counter — which is what makes the deadline engine idempotent
        // (§3) without a "have I sent this?" lookup table.
        //
        // No DB unique on (reporting_period_id, project_id): project_id is
        // nullable (MDA-level obligations arrive in Phase 2) and SQL treats
        // NULLs as distinct, so the constraint would enforce nothing on
        // exactly the rows it is meant to protect. The generator upserts on
        // the triple inside a locked transaction instead, with a test.
        Schema::create('report_obligations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('reporting_period_id')->constrained()->restrictOnDelete();
            // Null = an MDA-level obligation (Phase 2 consolidation).
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            // Copied at generation. reporting:generate-obligations refreshes it
            // for UNFULFILLED rows only, so moving a future deadline works
            // while history stays immutable.
            $table->timestamp('due_at');
            $table->string('status', 12)->default('pending');   // ReportObligationStatus
            // The FK is added in the progress_reports migration — the two
            // tables reference each other, and the constraint has to wait for
            // the referenced table to exist.
            $table->unsignedBigInteger('progress_report_id')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->boolean('submitted_late')->default(false);
            // Monotonic index into reminder_days_before. The whole idempotency
            // story of the deadline engine is this counter plus a row lock.
            $table->unsignedTinyInteger('reminder_stage')->default(0);
            $table->timestamp('reminder_last_sent_at')->nullable();
            $table->timestamp('overdue_notified_at')->nullable();
            $table->unsignedTinyInteger('escalation_stage')->default(0);
            $table->timestamp('escalated_at')->nullable();
            $table->foreignId('waived_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('waived_at')->nullable();
            $table->text('waiver_reason')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'reporting_period_id', 'status']);  // league table
            $table->index(['status', 'due_at']);                            // deadline sweep
            $table->index(['tenant_id', 'project_id', 'reporting_period_id']);
            $table->index('progress_report_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_obligations');
    }
};
