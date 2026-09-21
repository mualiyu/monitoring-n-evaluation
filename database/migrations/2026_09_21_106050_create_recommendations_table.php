<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned. The manual's knowledge-management loop (digest §7.9):
        // what an evaluation, a progress report or an inspection told the
        // state to do, who owns it, what it costs, when it is due, and whether
        // it happened.
        //
        // THIS TABLE IS WHY EVALUATIONS MATTER. An evaluation nobody acts on
        // is a document; the only difference between the two is a register
        // that can be filtered to "outstanding and past due" and handed to a
        // commissioner. Every column below exists to make that one query
        // answerable and that one list exportable.
        Schema::create('recommendations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            // POLYMORPHIC SOURCE — an evaluation, a progress report, an
            // inspection, an exception report. Written out rather than via
            // morphs() so the index can lead with tenant_id like every other
            // index in this schema. Laravel's default morph map (the FQCN) is
            // used: there is no morph-map config to register an alias in, and
            // this module may not add one.
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            // The delivery record this recommendation bites on, captured at
            // raise time from the source. Provenance, not a live mirror: it is
            // what makes "show me every outstanding recommendation on this
            // road" a single indexed query instead of a join across four
            // possible source tables.
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('body');
            // The addressee, both ways round. A user is someone the platform
            // can notify and chase; a body ("Ministry of Finance", "State
            // Executive Council") is who the evaluation actually addressed,
            // and is frequently not a platform account at all. Recording only
            // the user would lose half the register.
            $table->foreignId('addressee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('addressee_body')->nullable();
            $table->string('priority', 12)->default('medium');  // RecommendationPriority
            $table->string('status', 16)->default('open');      // RecommendationStatus
            // "prioritized, costed, timetabled" — the manual's three demands
            // on a recommendation (digest §4).
            $table->decimal('estimated_cost', 18, 2)->nullable();
            $table->string('timeline')->nullable();             // "within two budget cycles"
            $table->date('due_on')->nullable();                 // the date the sweep judges
            $table->text('implementation_evidence')->nullable();
            $table->text('follow_up_notes')->nullable();
            // OVERDUE IS NOT STORED AS A FLAG. It is derived —
            // outstanding AND due_on < today — because a boolean column would
            // be wrong every night between the deadline passing and the sweep
            // running, and a stale status on a deadline is a compliance
            // defect. This stamp is the sweep's monotonic idempotency gate:
            // set once, under a row lock, in the same transaction as the
            // dispatch, so a double cron run cannot double-notify.
            $table->timestamp('overdue_flagged_at')->nullable();
            $table->foreignId('raised_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('raised_at')->nullable();
            $table->foreignId('accepted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('implemented_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('implemented_at')->nullable();
            $table->text('closure_reason')->nullable();         // why declined / superseded
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'status', 'due_on']);   // the overdue sweep + the register
            $table->index(['tenant_id', 'source_type', 'source_id']);
            $table->index(['tenant_id', 'addressee_id']);
            $table->index(['tenant_id', 'project_id']);
        });

        // Self-reference: which recommendation replaced this one. Added after
        // creation because the table cannot constrain itself inside its own
        // create() on every driver, and it keeps the column's meaning next to
        // the comment that explains it.
        Schema::table('recommendations', function (Blueprint $table) {
            $table->foreignId('superseded_by_id')->nullable()->after('closure_reason')
                ->constrained('recommendations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            // By column, never by name: dropForeign(['column']) is the form
            // SQLite tolerates.
            $table->dropForeign(['superseded_by_id']);
            $table->dropColumn('superseded_by_id');
        });

        Schema::dropIfExists('recommendations');
    }
};
