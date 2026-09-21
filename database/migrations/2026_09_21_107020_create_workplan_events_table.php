<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned and APPEND-ONLY — the same pattern as
        // progress_report_events and project_status_events, for the same
        // reason: the plan's own *_by_id/*_at columns hold the CURRENT chain
        // state, which cannot express a plan rejected twice, and cannot
        // answer "how long does this MDA take to approve its work plan"
        // without a self-join against a history it does not have.
        //
        // No soft deletes: an audit row is never removed, so a deleted_at
        // column would only offer a way to hide one.
        Schema::create('workplan_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('workplan_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 12)->nullable();  // null on creation
            $table->string('to_status', 12);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['tenant_id', 'workplan_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workplan_events');
    }
};
