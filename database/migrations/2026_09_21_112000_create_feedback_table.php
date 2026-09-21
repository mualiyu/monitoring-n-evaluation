<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // GLOBAL TABLE — deliberately carries no tenancy column, and the
        // discipline sweep (tests/Unit/TenancyDisciplineTest.php) is what
        // keeps that true.
        //
        // The reasoning: a citizen standing next to a half-built clinic does
        // not know, and must not be asked, which ministry owns it. Feedback
        // arrives on the apex domain where no MDA subdomain has been resolved,
        // so there is no tenant to scope the write to. Tenancy is carried by
        // the PROJECT the feedback points at — that row is tenant-owned and
        // globally scoped, so `whereHas('project')` narrows an MDA's
        // moderation queue to its own projects automatically, with no manual
        // tenant clause anywhere.
        //
        // Feedback with a null project (general comment, or a project later
        // purged) therefore has no MDA anchor at all and is moderated by state
        // oversight. That is the correct fallback: unowned public correspondence
        // belongs to the secretariat, not to whichever MDA happens to look.
        Schema::create('feedback', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            // nullOnDelete, not restrict: a hard-deleted project must not take
            // the public record of what people said about it with it, and it
            // must not be able to block its own removal either. The row
            // survives and falls to the oversight queue.
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();

            $table->string('subject');
            $table->text('body');

            // All optional — anonymous feedback is legitimate feedback, and a
            // mandatory name on a complaint about a contractor is a deterrent
            // dressed up as data quality.
            $table->string('submitter_name')->nullable();
            $table->string('submitter_email')->nullable();
            $table->string('submitter_phone', 32)->nullable();

            $table->string('channel', 20)->default('portal');   // FeedbackChannel
            $table->string('status', 12)->default('pending');   // FeedbackStatus

            $table->foreignId('moderated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->string('moderation_reason', 1000)->nullable();

            // The spam heuristic MARKS, it never rejects: an over-eager filter
            // silently eating a genuine complaint about a stalled project is a
            // worse failure than a moderator seeing one line of junk.
            $table->boolean('flagged_as_spam')->default(false);
            $table->string('spam_reason', 255)->nullable();

            // Abuse handling only. Never rendered on the portal, and never
            // fillable — SubmitFeedback reads them from the request so no form
            // payload can forge either one.
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The moderation queue's own ordering (oldest pending first) and
            // the portal's per-project read.
            $table->index(['status', 'created_at']);
            $table->index(['project_id', 'status']);
            $table->index('flagged_as_spam');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
