<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned validation ledger — the twin of progress_report_events
        // and project_status_events, for the same reason: the *_by_id / *_at
        // columns on indicator_readings carry the CURRENT state, which cannot
        // express a figure rejected twice, and cannot answer "how long does
        // data-quality review take in this state" without a self-join against
        // a history it does not have.
        //
        // Append-only. No update or delete Action exists, and the model
        // refuses both — an audit trail a feature can edit is not one.
        Schema::create('indicator_reading_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('indicator_reading_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 12)->nullable();      // null = creation
            $table->string('to_status', 12);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();                 // required on a rejection
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['tenant_id', 'indicator_reading_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indicator_reading_events');
    }
};
