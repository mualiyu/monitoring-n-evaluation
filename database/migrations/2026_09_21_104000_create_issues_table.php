<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The challenges register (plan §4). Tenant-owned: an obstruction is
        // an MDA's own record of what is stopping its work.
        //
        // ALWAYS against a project, OPTIONALLY against the record that
        // surfaced it. The polymorphic `source` is what turns "challenges"
        // from a narrative paragraph on a monthly return into a register: the
        // Nasarawa lifecycle has a contractor describing challenges in step 3
        // and an inspector finding them in step 2, and both have to land on
        // one list without either becoming the other's foreign key.
        //
        // Nullable because the commonest raise of all is someone simply
        // knowing about a problem — a phone call from site is not a record in
        // this system, and requiring one would push the issue off the register.
        Schema::create('issues', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            // nullableMorphs, not a constrained FK: the source may be a
            // progress report, a site inspection or an evaluation, and two of
            // those three tables do not exist yet. An issue outliving its
            // source record is correct — the obstruction was real.
            $table->nullableMorphs('source');
            $table->string('title');
            $table->text('description');
            $table->string('category', 32);                 // IssueCategory
            $table->string('severity', 12);                 // IssueSeverity
            $table->string('status', 16)->default('open');  // IssueStatus — chokepoint only
            // The person accountable for CLEARING it, which is rarely the
            // person who raised it: a consultant reports a cash-release
            // problem, an M&E officer owns chasing it.
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('corrective_action')->nullable();
            $table->date('due_date')->nullable();
            $table->text('resolution_note')->nullable();
            $table->foreignId('raised_by_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('acknowledged_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('resolved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('closed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            // The escalation ladder's idempotency gate, advanced under a row
            // lock in the same transaction as the dispatch — exactly as
            // report_obligations.escalated_at is. A null here is what makes
            // "escalate this once, ever" structural rather than a query the
            // sweep has to get right twice.
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'severity', 'status']);
            $table->index(['tenant_id', 'project_id', 'status']);
            // The overdue filter and the escalation sweep both read this pair.
            $table->index(['tenant_id', 'due_date']);
            $table->index(['tenant_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issues');
    }
};
