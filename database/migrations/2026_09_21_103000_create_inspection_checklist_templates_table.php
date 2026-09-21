<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // GLOBAL REFERENCE DATA — deliberately no tenant_id, for the same
        // reason reporting_periods has none (progress-reporting.md §1.1): a
        // state's inspection checklist is state-wide. The standing question
        // oversight asks is "how did road projects score on the drainage item
        // this quarter, across every MDA" — and that is only answerable if
        // every MDA answered the SAME item. A per-tenant checklist would give
        // forty ministries forty vocabularies and one unanswerable question.
        //
        // The trade is accepted with eyes open: an MDA cannot invent its own
        // item. That is a feature here. Templates are curated by the state
        // (InspectionChecklistSeeder) and an MDA that needs a question added
        // asks the secretariat, exactly as it would for an indicator
        // definition.
        Schema::create('inspection_checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            // Which visit this checklist is written for. Null = usable for any
            // type — the general-purpose sweep a state starts with before it
            // has written type-specific instruments.
            $table->string('inspection_type', 20)->nullable();   // InspectionType
            // Sector-specific instruments (a road checklist is not a clinic
            // checklist). Null = every sector. `sectors` is itself global
            // reference data, so this FK crosses no tenancy boundary.
            $table->foreignId('sector_id')->nullable()->constrained()->nullOnDelete();
            // Retiring a checklist must not rewrite the inspections that used
            // it, so templates are deactivated rather than deleted: past
            // responses keep pointing at the items they actually answered.
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'inspection_type']);
            $table->index('sector_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_checklist_templates');
    }
};
