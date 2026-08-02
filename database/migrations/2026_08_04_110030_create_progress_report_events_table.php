<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned chain ledger (progress-reporting.md §1.4), the twin of
        // project_status_events.
        //
        // The *_by_id / *_at columns on progress_reports carry the CURRENT
        // chain state, which is what lists and the league table read. This
        // table carries the HISTORY, which those columns cannot: a report
        // returned twice has two return events and one returned_at.
        //
        // Append-only. No update or delete Action exists, and the model
        // refuses both — an audit trail a feature can edit is not one.
        Schema::create('progress_report_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('progress_report_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 12)->nullable();      // null = creation
            $table->string('to_status', 12);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();                 // required on `returned`
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['tenant_id', 'progress_report_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('progress_report_events');
    }
};
