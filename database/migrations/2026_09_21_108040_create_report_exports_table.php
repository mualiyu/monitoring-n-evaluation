<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The generated-artifact register (plan §4 "Reporting & consolidation"):
        // who generated what, under which filters, when, in which format, and
        // where the file went. EVERY export in the platform lands here.
        //
        // GLOBAL, with a nullable provenance column — read the next paragraph
        // before adding a tenancy key.
        //
        // ⚠ `generated_for_tenant_id`, NOT `tenant_id`, and the difference is
        // load-bearing. This register records oversight exports (which cross
        // every MDA and belong to none) alongside workspace exports (which do
        // not). A scope key would hide the state's own exports from the state
        // and force the register to exist twice. The column is therefore
        // PROVENANCE — "this artifact was generated for that workspace" — and
        // the authorization that uses it lives in ReportExportPolicy, which
        // refuses a download when the bound tenant is not the one the file was
        // generated for. Scope keys are enforced by the database; this one is
        // enforced by a policy, on purpose, because the register is global.
        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('generated_for_tenant_id')->nullable()
                ->constrained('tenants')->nullOnDelete();

            $table->string('dataset', 30);                     // ReportDataset
            $table->string('format', 10);                      // ExportFormat
            $table->string('surface', 12)->default('oversight');
            $table->string('title');

            // What was asked for. Stored so a figure in a forwarded
            // spreadsheet can be traced back to the question it answered —
            // "3 218 projects" means nothing without the filter that produced
            // it.
            $table->json('filters')->nullable();
            $table->json('columns')->nullable();
            $table->string('group_by', 40)->nullable();

            // pending → ready | failed. Deliberately NOT a state machine
            // enum: there is no domain lifecycle here, only a job that has or
            // has not finished. The one state machine in this module belongs
            // to the consolidation.
            $table->string('status', 12)->default('pending');
            $table->unsignedInteger('row_count')->nullable();
            $table->boolean('truncated')->default(false);
            $table->text('error_message')->nullable();

            // The stored artifact. Private disk always (rules/security.md
            // §Uploads) — nothing this platform generates sits under public/,
            // and the filename on disk is a ULID, never anything a user typed.
            $table->string('disk', 40)->nullable();
            $table->string('path')->nullable();
            $table->string('file_name');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            $table->foreignId('consolidated_report_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->foreignId('generated_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            // Retention is a config concern, not a feature: the sweep that
            // prunes expired artifacts reads this column and never touches the
            // register row itself.
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['generated_by_id', 'created_at']);
            $table->index(['dataset', 'created_at']);
            $table->index(['generated_for_tenant_id', 'created_at'], 'report_exports_provenance_index');
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
