<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned. Who is conducting the evaluation: the lead, the team,
        // and the external evaluators who hold no account on this platform.
        //
        // WHY user_id IS NULLABLE. The manual's implementation plan has states
        // "bidding to contract evaluation consultants" — most real evaluation
        // teams are external firms and academics. Requiring a platform account
        // for every named team member would mean either provisioning logins
        // for people who will never sign in, or leaving the team blank. The
        // free-text columns record them; user_id records the ones who are also
        // users, and it is the only kind the separation-of-duties guard can
        // match an actor against.
        Schema::create('evaluation_team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('evaluation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_name')->nullable();
            $table->string('external_organisation')->nullable();
            // `lead` | `member`. Exactly one lead per evaluation, enforced in
            // AssignEvaluationTeam under a row lock — NOT by a partial unique
            // index, which MySQL does not have and which soft deletes would
            // defeat anyway.
            $table->string('role', 20)->default('member');
            $table->string('expertise')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'evaluation_id']);
            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_team_members');
    }
};
