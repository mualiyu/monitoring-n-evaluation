<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned. One row per evaluation criterion — the OECD-DAC five
        // (relevance, efficiency, effectiveness, impact, sustainability) that
        // `platform.evaluation.criteria` ships with, plus whatever a state
        // adds to them.
        //
        // ROWS, NOT A COLUMN PER CRITERION. `config('platform.evaluation.criteria')`
        // is configurable precisely so a state can add "gender responsiveness"
        // to the DAC five; a column per criterion would make that a migration
        // and a release, which is the opposite of configurable. It would also
        // leave nowhere to put the justification and the evidence reference
        // that make a score defensible — a bare 4/5 with no reasoning is an
        // opinion, and an evaluation is supposed to be the other thing.
        //
        // THE OVERALL SCORE IS NOT STORED. It is the weighted mean of these
        // rows (Evaluation::overallScore()). Storing it as well would give two
        // answers to one question the first time a justification is corrected.
        Schema::create('evaluation_criterion_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('evaluation_id')->constrained()->cascadeOnDelete();
            // The config key, not a label: labels are translated and states
            // rename them, while the key is what the scorecard matches on.
            $table->string('criterion', 60);
            // Nullable: a criterion row exists from the moment the evaluation
            // is commissioned, so the scorecard shows what still has to be
            // answered. Null means "not yet scored", which is emphatically not
            // the same as zero.
            $table->decimal('score', 5, 2)->nullable();
            // Relative weight in the overall score. Defaults to parity, which
            // is the DAC convention; a state weighting sustainability double
            // changes a number rather than the arithmetic.
            $table->decimal('weight', 5, 2)->default(1);
            $table->text('justification')->nullable();
            // Where the finding behind this score can be read: a section of
            // this report, an indicator, a document in the vault. Free text —
            // a typed FK would have to point at four different tables.
            $table->string('evidence_reference')->nullable();
            $table->foreignId('scored_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scored_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // No DB unique on (evaluation_id, criterion): soft deletes make it
            // either block re-adding a criterion that was removed, or — with
            // deleted_at in the key — enforce nothing. RecordCriterionScore
            // upserts under lockForUpdate instead, with a test.
            $table->index(['tenant_id', 'evaluation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_criterion_scores');
    }
};
