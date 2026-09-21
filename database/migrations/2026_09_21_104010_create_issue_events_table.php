<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned lifecycle ledger — the twin of progress_report_events
        // and project_status_events, for the same reason. The issue's own
        // *_by_id/*_at columns hold the CURRENT state; this table holds the
        // HISTORY, which those columns cannot express: an issue resolved,
        // reopened and resolved again has two resolve events and one
        // resolved_at, and "how long does this MDA take to clear an access
        // dispute" is unanswerable without the history.
        //
        // Append-only. No update or delete Action exists and the model refuses
        // both — an audit trail a feature can edit is not one.
        Schema::create('issue_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 16)->nullable();  // null = the raise
            $table->string('to_status', 16);
            // NULLABLE, unlike the reporting ledger: the escalation rung is
            // raised by the threshold engine, which has no human actor. A
            // system escalation attributed to whoever happened to trigger the
            // sweep would be a lie in an audit record, and attributing it to a
            // sentinel user would be the same lie with extra machinery.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();             // required on a close that skips resolution
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['tenant_id', 'issue_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_events');
    }
};
