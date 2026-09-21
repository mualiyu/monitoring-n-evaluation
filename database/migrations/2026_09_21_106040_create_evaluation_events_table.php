<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned lifecycle ledger — the twin of project_status_events
        // and progress_report_events, for the same reason: the *_by_id/*_at
        // columns on `evaluations` carry the CURRENT chain state, which cannot
        // express a report sent back twice, and cannot answer "how long did
        // this secretariat sit on the draft" without a history it does not
        // have.
        //
        // Append-only. No update or delete Action exists and the model refuses
        // both — an audit trail a feature can edit is not one.
        Schema::create('evaluation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('evaluation_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();   // null = commissioning
            $table->string('to_status', 20);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();              // required on cancel / send-back
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['tenant_id', 'evaluation_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_events');
    }
};
