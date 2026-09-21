<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The logframe (plan §4, manual digest §2). ONE row per RESULT
        // STATEMENT, not one row per framework: a framework is the set of
        // statements sharing a project (or, with a null project_id, an MDA
        // programme), rooted at impact and nested impact → outcome → output.
        //
        // A separate "framework header" table was considered and rejected: it
        // would carry a title and nothing else, and every screen would join
        // through it to reach the statements that are the actual content.
        //
        // project_id is NULLABLE and RESTRICTS, matching indicators: an MDA
        // programme framework belongs to no single project, and a project
        // carrying a framework cannot be force-deleted out from under it.
        Schema::create('result_frameworks', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->restrictOnDelete();
            // Self-reference: an outcome hangs off an impact, an output off an
            // outcome. restrictOnDelete — a branch is dismantled leaf-first, by
            // a person, never by a cascade nobody watched.
            $table->foreignId('parent_id')->nullable()->constrained('result_frameworks')->restrictOnDelete();
            $table->string('level', 20);                          // FrameworkLevel
            // The logframe reference an MDA writes on paper ("1.2.3"). Free
            // text on purpose: numbering conventions differ per state, and a
            // generated one would fight the numbering in the printed AWPB.
            $table->string('code', 40)->nullable();
            $table->string('statement');
            $table->text('description')->nullable();
            // Every logframe row carries the assumption it rests on — the
            // condition outside the MDA's control that has to hold for this
            // result to follow from the one below it.
            $table->text('assumptions')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'project_id', 'level']);
            $table->index(['tenant_id', 'parent_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('result_frameworks');
    }
};
