<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned lifecycle ledger — the twin of progress_report_events
        // and project_status_events, and for the same reason: the *_by_id /
        // *_at columns on site_inspections hold the CURRENT state, which
        // cannot express history. A visit rescheduled twice has two events and
        // one scheduled_date, and "how long does this MDA take between a visit
        // and its report" needs the history, not the snapshot.
        //
        // Append-only. No update or delete Action exists and the model refuses
        // both — an audit trail a feature can edit is not one.
        Schema::create('site_inspection_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_inspection_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 12)->nullable();     // null = creation
            $table->string('to_status', 12);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();                // required on `cancelled`
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['tenant_id', 'site_inspection_id', 'occurred_at'], 'site_inspection_events_timeline_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_inspection_events');
    }
};
