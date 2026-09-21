<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // GLOBAL — deliberately NO tenant_id, and this is the design, not an
        // omission (same reasoning as reporting_periods, progress-reporting.md
        // §1.1, and asserted by tests/Unit/TenancyDisciplineTest).
        //
        // A consolidation is a STATE record that spans every MDA: the
        // Secretariat's roll-up for the Commissioner (manual digest §1, §4).
        // Giving it a tenant_id would be wrong twice over — it would claim the
        // artifact belongs to one MDA, and the global scope would then hide
        // the state's own report from the state.
        //
        // What is tenant-owned is the INPUT (progress_reports,
        // report_obligations, indicator_readings — all scoped, all read
        // through app/Actions/Oversight with an explicit bypass) and the
        // per-MDA figures land in consolidated_report_entries, which name
        // their subject with a PROVENANCE column, not a scope key.
        Schema::create('consolidated_reports', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('reference', 40)->unique();          // human handle, e.g. APR-2026
            $table->string('title');
            $table->string('type', 20);                          // ConsolidatedReportType
            $table->string('status', 20)->default('draft');      // ConsolidationStatus
            $table->foreignId('reporting_period_id')->constrained()->restrictOnDelete();

            // THE FROZEN FIGURES. Written once, at approval, by
            // ApproveConsolidation. An APR whose numbers move when an MDA
            // edits last quarter's return is not an APR — so the signed
            // artifact carries its own copy of every figure and narrative it
            // was signed over, and the export reads THIS, never the live
            // tables, once the report is approved.
            $table->json('snapshot')->nullable();
            $table->timestamp('snapshot_taken_at')->nullable();

            // The live roll-up totals from the most recent compile. Replaced
            // on every recompile; superseded by `snapshot` at approval.
            $table->json('totals')->nullable();
            $table->unsignedInteger('entity_count')->default(0);   // MDAs with figures
            $table->unsignedInteger('denominator')->default(0);    // MDAs expected to report

            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('compiled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('compiled_at')->nullable();
            $table->foreignId('submitted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('published_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('returned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable();
            $table->text('return_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'reporting_period_id']);
            $table->index(['type', 'status']);
            $table->index('published_at');

            // NOT a unique index on (reporting_period_id, type), although only
            // one APR may exist per year: soft deletes make such a key either
            // block re-opening a window after a discard, or — with deleted_at
            // in the key — enforce nothing at all. OpenConsolidation enforces
            // it inside a locked transaction instead, exactly as
            // StartProgressReport does for the one-live-report rule.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consolidated_reports');
    }
};
