<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned. Step 1 of the Nasarawa BPP monitoring lifecycle
        // (digest §8): within `monitoring.commencement_notice_days` of award,
        // the supervising agency issues the contractor a notice to commence,
        // quoting title, scope, contract sum, duration, contractor and
        // expected completion.
        //
        // WHY THE ROW EXISTS BEFORE IT IS ISSUED: the notice is an OBLIGATION
        // created by the award, exactly as a report obligation is created by a
        // reporting window. Modelling only issued notices would leave the
        // overdue sweep with nowhere to record "already chased" — the
        // idempotency gate of every sweep in this platform is a column on the
        // row it acts on (progress-reporting.md §3), never a memo table. So
        // the sweep materialises a `pending` notice for an overdue contract
        // and flags it there.
        //
        // THE SNAPSHOT COLUMNS (scope, sum, duration, dates, agency) are
        // copies, not joins: a notice is a document that was served on a date,
        // and a later contract variation must not rewrite what the contractor
        // was actually told. The live contract stays one hop away for anyone
        // who wants today's figures.
        Schema::create('commencement_notices', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            // The firm served. Global registry, so no tenancy line is crossed
            // (see Contractor) — restrict, because a vendor record a served
            // notice names cannot be deleted out from under it.
            $table->foreignId('contractor_id')->constrained()->restrictOnDelete();

            $table->string('reference', 64)->nullable();
            $table->string('status', 16)->default('pending'); // CommencementNoticeStatus

            // --- What the contractor was told, as at service ---
            $table->text('scope_of_works')->nullable();
            $table->decimal('contract_sum', 18, 2);
            $table->unsignedInteger('duration_days')->nullable();
            $table->date('commencement_date')->nullable();
            $table->date('expected_completion_date')->nullable();
            // The supervising agency NAME, snapshotted: white-label, so it is
            // whatever the tenant record (or the project's named supervisor)
            // said at the time, never a constant in code.
            $table->string('supervising_agency_name')->nullable();
            $table->text('instructions')->nullable();

            // --- The statutory clock ---
            // award_date + `monitoring.commencement_notice_days`, snapshotted
            // at creation so a later policy change cannot retroactively make a
            // served notice late (the reasoning behind progress_reports.due_at).
            $table->date('due_at');
            $table->boolean('issued_late')->default(false);
            // The overdue sweep's idempotency gate: null until the MDA admin
            // has been told once, ever.
            $table->timestamp('overdue_notified_at')->nullable();

            // --- The chain: who served it, and when it was acknowledged ---
            $table->foreignId('issued_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('acknowledged_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_by_contractor_at')->nullable();
            $table->text('acknowledgement_note')->nullable();

            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // One notice per contract, in the schema rather than in an
            // Action's good behaviour. A soft-deleted notice keeps its slot on
            // purpose: a served government notice is withdrawn on the record,
            // never deleted and re-served as if the first had not happened.
            $table->unique(['tenant_id', 'contract_id']);
            $table->index(['tenant_id', 'project_id']);
            $table->index(['tenant_id', 'status', 'due_at']); // the overdue sweep
            $table->index(['tenant_id', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commencement_notices');
    }
};
