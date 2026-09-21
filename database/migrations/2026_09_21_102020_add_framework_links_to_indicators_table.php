<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Attaches the Phase 1 indicator sheet to the Phase 2 results
        // framework. The definition columns the manual's matrices require
        // (data_source, means_of_verification, responsible_collector_id /
        // _text, smart_justification, baseline, unit, frequency, target_type)
        // were frozen in 2026_08_03_100200 and are NOT repeated here — the
        // shape was deliberately over-provisioned then so this migration adds
        // links, not fields.
        //
        // result_framework_id already exists as an unconstrained column,
        // precisely so this table could arrive without migrating readings; the
        // constraint is what is new.
        Schema::table('indicators', function (Blueprint $table) {
            $table->foreign('result_framework_id')
                ->references('id')->on('result_frameworks')
                ->restrictOnDelete();

            // The indicator this one rolls up into: an output indicator
            // contributing to an intermediate outcome indicator, which
            // contributes to the PDO. The TREE OF STATEMENTS
            // (result_frameworks.parent_id) and the TREE OF MEASURES are not
            // the same tree — two outputs under different outcomes can feed
            // one PDO indicator — so the link lives here as well.
            $table->foreignId('parent_indicator_id')->nullable()->after('result_framework_id')
                ->constrained('indicators')->restrictOnDelete();

            // The state library entry this indicator was instantiated from.
            // Nullable: an MDA may still define a local indicator the library
            // has no entry for, and the Q4 indicator retreat is where those
            // get promoted into the library.
            $table->foreignId('indicator_definition_id')->nullable()->after('parent_indicator_id')
                ->constrained()->restrictOnDelete();

            // "Indicator focus" from the manual's matrices.
            $table->string('focus')->nullable()->after('definition');

            $table->index(['tenant_id', 'tier']);
            $table->index('indicator_definition_id');
        });
    }

    public function down(): void
    {
        Schema::table('indicators', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'tier']);
            $table->dropIndex(['indicator_definition_id']);
            $table->dropForeign(['result_framework_id']);
            $table->dropForeign(['parent_indicator_id']);
            $table->dropForeign(['indicator_definition_id']);
            $table->dropColumn(['parent_indicator_id', 'indicator_definition_id', 'focus']);
        });
    }
};
