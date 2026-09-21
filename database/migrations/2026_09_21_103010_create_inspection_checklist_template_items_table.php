<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Global, like its parent. One row per question an inspector answers.
        Schema::create('inspection_checklist_template_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            // cascadeOnDelete, against the platform's restrict-by-default rule
            // and deliberately: an item has no meaning without its template,
            // and the template itself is soft-deleted, so nothing is actually
            // cascading in normal operation. A hard delete is a fixture teardown.
            $table->foreignId('inspection_checklist_template_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->text('prompt');                              // the question as the inspector reads it
            $table->text('guidance')->nullable();                // what "acceptable" means, for a non-specialist
            $table->string('response_type', 12);                 // ChecklistResponseType
            // Whether the UNFAVOURABLE answer is a finding that must be
            // written up. For yes_no that is a "no"; for rating/numeric it is
            // a value at or below `finding_threshold`. Some items are purely
            // descriptive ("how many workers on site today?") and record a
            // number without ever being a failure — hence the flag.
            $table->boolean('finding_on_no')->default(true);
            $table->decimal('finding_threshold', 8, 2)->nullable();
            $table->unsignedTinyInteger('rating_scale')->nullable();  // rating items: 1..N
            $table->string('unit', 30)->nullable();                   // numeric items: "m³", "workers"
            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['inspection_checklist_template_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_checklist_template_items');
    }
};
