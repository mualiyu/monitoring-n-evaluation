<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned (plan §4 "Evaluation"). A commissioned study of a
        // project OR of an MDA programme — hence the nullable project_id and
        // the `scope` descriptor beside it. An evaluation of "the state's
        // rural water programme" has no single project row to hang off, and
        // forcing one would either invent a fake project or push every
        // programme evaluation out of the system entirely.
        //
        // Guarded-by-omission, exactly as projects and progress_reports are:
        // `status` and the whole chain (commissioned_*, submitted_*,
        // approved_*, published_*, cancellation_reason, status_changed_at) are
        // written ONLY by App\Actions\Evaluation\TransitionEvaluationStatus,
        // so none of them is fillable and no ->update($validated) can reach
        // them.
        Schema::create('evaluations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            // restrictOnDelete, not cascade: an evaluation is a government
            // record that outlives the thing it evaluated. Projects soft-delete
            // anyway, so this only bites on a hard delete — which is exactly
            // when it should.
            $table->foreignId('project_id')->nullable()->constrained()->restrictOnDelete();
            // What is being evaluated when it is not a single project:
            // `project` | `programme` | `entity` | `sector` | `thematic`.
            // A free-ish descriptor rather than an enum because the unit of
            // evaluation is a state's own vocabulary; the UI offers a list.
            $table->string('scope', 20)->default('project');
            // The programme/entity name, when project_id is null. Never a
            // state or ministry literal in code — it is typed per record.
            $table->string('subject_name')->nullable();
            $table->string('type', 20);                     // EvaluationType
            $table->string('status', 20)->default('planned'); // EvaluationStatus
            $table->string('title');
            // Why this evaluation exists, and the questions it must answer —
            // the manual's §2 "objectives & scope (evaluation questions)".
            $table->text('purpose');
            $table->text('evaluation_questions')->nullable();
            $table->text('methodology_summary')->nullable();
            // Who commissioned it. A body (MEP&B secretariat, a development
            // partner, the MDA itself) that may have no user account here.
            $table->string('sponsor');
            // The work plan: [{label, starts_on, ends_on}] — desk review,
            // fieldwork, validation workshop, reporting (manual Appendix A).
            //
            // JSON, deliberately, where sections and scores are rows: nothing
            // queries, filters or aggregates a phase, and no second record
            // hangs off one. Rows earn their table by being addressable —
            // a section is edited by id, a score is written per criterion —
            // and a phase list is neither. It is a schedule annotation read
            // and rewritten whole.
            $table->json('phases')->nullable();
            // Evaluation budget (manual §6: best practice reserves 2–5% of
            // project budget for M&E). Money is decimal(18,2) + MoneyCast.
            $table->decimal('budget', 18, 2)->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->date('report_due_on')->nullable();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            // The chain — current state for lists; the history is the ledger.
            $table->foreignId('commissioned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('commissioned_at')->nullable();
            $table->foreignId('submitted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('published_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'project_id']);
            $table->index(['tenant_id', 'report_due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluations');
    }
};
