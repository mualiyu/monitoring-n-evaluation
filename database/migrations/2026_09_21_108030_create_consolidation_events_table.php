<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only chain ledger for a consolidation, the same shape as
        // progress_report_events and project_status_events. GLOBAL, like its
        // parent.
        //
        // "Who compiled these figures, who sent them up, who signed them, and
        // when" is the first question an auditor asks about a state report —
        // and the answer must survive the report being recompiled, returned
        // and recompiled again. No update path, no delete path, no soft
        // deletes: rules/security.md, the audit trail is append-only.
        Schema::create('consolidation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consolidated_report_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();   // null on the opening row
            $table->string('to_status', 20);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['consolidated_report_id', 'occurred_at'], 'consolidation_events_report_time_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consolidation_events');
    }
};
