<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The manual's Exception Report (Table 5.2: filed "on critical
        // incidence / high deviation"), tenant-owned.
        //
        // THE RECORD EXPLAINS ITSELF. measured_value, threshold_value and the
        // three progress snapshots are stored rather than re-derived, because
        // every input to the judgement is mutable: the tolerance is a setting
        // a state may retune, and physical/financial progress move every
        // month. A report that said only "schedule slippage" would, six months
        // later, be a claim nobody could check against a project whose figures
        // have since changed — and the first question at any audit is "on what
        // basis was this raised?".
        Schema::create('exception_reports', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            // The corrective work this notice provoked. An exception report is
            // a NOTICE; the work is an Issue. nullOnDelete because a report
            // outliving its issue is still a true record of the deviation.
            $table->foreignId('issue_id')->nullable()->constrained()->nullOnDelete();
            $table->string('trigger', 32);                  // ExceptionTrigger
            $table->string('severity', 12);                 // IssueSeverity — one vocabulary for both registers
            $table->string('status', 16)->default('open');  // ExceptionStatus — chokepoint only
            $table->text('narrative');
            // The figure that tripped it and the tolerance it tripped, in the
            // trigger's own unit (percentage points, or days).
            $table->decimal('measured_value', 8, 2)->nullable();
            $table->decimal('threshold_value', 8, 2)->nullable();
            // Where the project stood at the moment of measurement. Null on a
            // manually raised report, and null for financial progress on a
            // project with no contract value — an unpriced project is not a
            // project at 0% spend.
            $table->decimal('physical_progress', 5, 2)->nullable();
            $table->decimal('schedule_elapsed', 5, 2)->nullable();
            $table->decimal('financial_progress', 5, 2)->nullable();
            $table->timestamp('measured_at');
            // Null = raised by the threshold engine. See the note on
            // issue_events.actor_id: a machine judgement is recorded as one.
            $table->foreignId('raised_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('acknowledged_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('resolved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'severity', 'status']);
            // The duplicate gate's index: "is there already a live report of
            // THIS trigger against THIS project" is the question the engine
            // asks once per project per sweep.
            $table->index(['tenant_id', 'project_id', 'trigger', 'status']);
            $table->index(['tenant_id', 'measured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exception_reports');
    }
};
