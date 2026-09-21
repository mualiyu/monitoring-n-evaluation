<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per MDA per consolidation: the figures the state rolled up
        // for that entity. GLOBAL, like its parent.
        //
        // ⚠ `subject_tenant_id`, NOT `tenant_id`. The distinction is the whole
        // point of the table. A tenancy key says "this row belongs to that
        // MDA and no other MDA may see it"; this column says "these are the
        // figures ABOUT that MDA, in a state document every MDA's figures
        // appear in". Naming it after the scope key would make the discipline
        // sweep demand BelongsToTenant, and the global scope would then hide
        // 39 of the 40 rows of the state's own report. Provenance, not scope.
        Schema::create('consolidated_report_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consolidated_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_tenant_id')->constrained('tenants')->restrictOnDelete();

            // Portfolio position (a STOCK measure, read as at the compile).
            $table->unsignedInteger('projects_total')->default(0);
            $table->json('projects_by_status')->nullable();
            $table->decimal('contract_value_total', 18, 2)->default(0);
            $table->decimal('expenditure_total', 18, 2)->default(0);
            $table->decimal('physical_progress_avg', 5, 2)->nullable();

            // Statutory reporting for the window (a FLOW measure).
            $table->unsignedInteger('obligations_expected')->default(0);
            $table->unsignedInteger('obligations_submitted')->default(0);
            $table->unsignedInteger('obligations_on_time')->default(0);
            $table->unsignedInteger('obligations_missed')->default(0);
            $table->unsignedInteger('obligations_waived')->default(0);

            // Returns actually filed for the window.
            $table->unsignedInteger('reports_filed')->default(0);
            $table->unsignedInteger('reports_approved')->default(0);
            $table->decimal('period_expenditure_total', 18, 2)->default(0);

            // Performance against the predetermined indicator list (manual
            // digest §4 — the APR's defining measurement).
            $table->unsignedInteger('indicators_reported')->default(0);
            $table->unsignedInteger('indicators_on_track')->default(0);
            $table->unsignedInteger('indicators_at_risk')->default(0);
            $table->unsignedInteger('indicators_off_track')->default(0);
            $table->unsignedInteger('readings_validated')->default(0);

            // The extension point. Inspections, issues, evaluations and
            // workplans are being built alongside this table; when they land,
            // their per-MDA counts arrive here without a migration and without
            // touching the consolidation screens.
            $table->json('figures')->nullable();

            $table->timestamp('compiled_at')->nullable();
            $table->timestamps();

            // No soft deletes, deliberately: an entry is DERIVED data that a
            // recompile rewrites in place (updateOrCreate on the unique key
            // below — that is what makes the compiler idempotent). A
            // soft-deleted row would sit inside the unique key and block the
            // rewrite. The audit record of what was signed is the frozen
            // snapshot on consolidated_reports, not these working rows.
            $table->unique(['consolidated_report_id', 'subject_tenant_id'], 'cre_report_subject_unique');
            $table->index('subject_tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consolidated_report_entries');
    }
};
