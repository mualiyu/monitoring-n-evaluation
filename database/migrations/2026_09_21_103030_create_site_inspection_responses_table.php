<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned: one row per checklist item an inspector answered.
        //
        // THE ANSWER IS TYPED, NOT STRINGIFIED. Three value columns instead of
        // one `value` varchar, because the questions this table exists to
        // answer are arithmetic — "what was the mean drainage rating on road
        // projects this quarter", "how many sites failed the safety item" —
        // and a string column makes both of those a full-table cast.
        //
        // `prompt` and `response_type` are SNAPSHOTS of the item as it read on
        // the day. The template is global, curated and editable; a report
        // printed in 2029 must show the question that was actually asked in
        // 2026, not the reworded one. This is the same instinct as
        // progress_reports.due_at.
        Schema::create('site_inspection_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_inspection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inspection_checklist_template_item_id')
                ->constrained('inspection_checklist_template_items')
                ->restrictOnDelete();

            $table->text('prompt');                       // the question as asked, snapshotted
            $table->string('response_type', 12);          // ChecklistResponseType, snapshotted

            $table->boolean('value_boolean')->nullable();
            $table->decimal('value_number', 10, 2)->nullable();
            $table->text('value_text')->nullable();

            // Judged by RecordChecklistResponses against the item's own rule,
            // then stored — so the finding count on a two-year-old inspection
            // does not move when the state retunes a threshold.
            $table->boolean('is_finding')->default(false);
            $table->text('note')->nullable();             // "what exactly is wrong"

            $table->timestamps();
            $table->softDeletes();

            // NO unique on (inspection, item), for the reason progress_reports
            // carries no unique either: soft deletes make it either block a
            // legitimate re-answer or, with deleted_at in the key, enforce
            // nothing. RecordChecklistResponses holds the one-answer-per-item
            // rule under a row lock, and a test proves it.
            $table->index(['tenant_id', 'site_inspection_id']);
            $table->index(['site_inspection_id', 'inspection_checklist_template_item_id'], 'site_inspection_responses_item_index');
            $table->index(['tenant_id', 'is_finding']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_inspection_responses');
    }
};
