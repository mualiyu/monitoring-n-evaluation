<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The transition ledger. Activitylog records *that* something changed,
        // generically; this typed table is what makes "average days from award
        // to mobilization per MDA" one indexed query instead of JSON mining.
        // Append-only: no update/delete Action exists, hence no soft deletes.
        Schema::create('project_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();         // null = creation
            $table->string('to_status', 20);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();                    // required for suspend/cancel/close-override
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['tenant_id', 'project_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_status_events');
    }
};
