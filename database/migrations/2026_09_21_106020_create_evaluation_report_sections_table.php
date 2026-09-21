<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned. The manual's eleven-section evaluation report
        // (digest §4) stored as ROWS, not as eleven text columns.
        //
        // The template is a state's own decision — one state numbers its
        // appendices differently, another wants a "gender and inclusion"
        // section, a third drops the acknowledgements. Eleven columns would
        // make every one of those a migration, and a report written under the
        // old template would silently lose the section that no longer has a
        // column. Rows make the template a setting
        // (`evaluation.report_sections`, resolved by ResolveReportTemplate)
        // and keep sections a past evaluation used even after the template
        // moves on.
        Schema::create('evaluation_report_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('evaluation_id')->constrained()->cascadeOnDelete();
            // Stable template key (executive_summary, findings, …) — what the
            // PDF template and the completeness guard match on, so renaming a
            // heading never breaks either.
            $table->string('key', 60);
            $table->string('heading');
            $table->unsignedSmallInteger('ordinal');
            // longText: a findings chapter with tables pasted in is not a
            // `text` column's 64 KB.
            $table->longText('body')->nullable();
            // Whether the report may go up for review without this section
            // written. Title page and appendices are not; findings are.
            $table->boolean('is_required')->default(true);
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('drafted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'evaluation_id', 'ordinal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_report_sections');
    }
};
