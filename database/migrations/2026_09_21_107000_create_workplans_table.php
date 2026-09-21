<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned. One MDA's Annual Work Plan & Budget for one year
        // (ondo-manual-digest §6: "Annual Work Plan (AWPB) per MDA: every
        // activity carries an output indicator").
        //
        // NO TOTAL BUDGET COLUMN, and no progress column. Both are sums over
        // workplan_activities and both are derived in exactly one place,
        // App\Support\WorkplanProgress. A stored total is a second answer to
        // a question that already has one, and the second answer is the one
        // that goes stale.
        Schema::create('workplans', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('title');
            // The year the plan covers. `year_basis` says whether that is a
            // calendar year or a financial year starting in it — Nigerian
            // states have run both, and a plan labelled "2026" that actually
            // runs Apr-26 → Mar-27 is exactly the ambiguity an M&E unit
            // cannot afford. period_start/period_end remain the authority for
            // every date comparison; the pair is presentation and filtering.
            $table->unsignedSmallInteger('year');
            $table->string('year_basis', 10)->default('calendar');
            $table->date('period_start');
            $table->date('period_end');
            // The officer accountable for delivering the plan (usually the
            // M&E focal officer); restrict, because a plan without an owner
            // is a plan nobody answers for.
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 12)->default('draft');     // WorkplanStatus
            $table->text('narrative')->nullable();              // strategy / assumptions
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            // The approval chain. Written ONLY by
            // App\Actions\Workplans\TransitionWorkplanStatus, which is why
            // none of these columns is fillable on the model.
            $table->foreignId('submitted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('closed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'year']);
            $table->index(['tenant_id', 'period_start']);
            // ONE PLAN PER (MDA, YEAR, BASIS) is NOT a unique index, for the
            // reason progress_reports gives: soft deletes make such a key
            // either block re-creation after a discard or, with deleted_at in
            // the key, enforce nothing. CreateWorkplan enforces it under a
            // lock instead.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workplans');
    }
};
