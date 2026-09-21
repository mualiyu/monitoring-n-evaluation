<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The narrative half of a consolidation — GLOBAL, for the same reason
        // its parent is (see the note on consolidated_reports).
        //
        // Sections are ROWS, not a JSON blob on the report, because each one
        // is separately authored, separately audited and separately rendered
        // into the PDF, and because the skeleton for a type
        // (ConsolidatedReportType::sectionSkeleton) is a contract a state is
        // judged on: a chapter may be left empty, but it cannot be quietly
        // dropped.
        Schema::create('consolidated_report_sections', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            // cascadeOnDelete, against the "restrict for domain data" default
            // and deliberately: a section has no existence apart from its
            // report, and the report itself soft-deletes — so this cascade
            // only ever fires on a forceDelete, where leaving orphaned
            // narrative behind would be the worse outcome.
            $table->foreignId('consolidated_report_id')->constrained()->cascadeOnDelete();
            $table->string('key', 60);                 // skeleton key, e.g. executive_summary
            $table->string('heading');
            $table->longText('body')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['consolidated_report_id', 'key']);
            $table->index(['consolidated_report_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consolidated_report_sections');
    }
};
