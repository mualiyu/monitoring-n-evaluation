<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who is accountable for this project. No soft deletes: re-assignment
        // reactivates the row (unassigned_at = null) rather than inserting a
        // duplicate, so the unique key stays meaningful and the row is the
        // audit record.
        Schema::create('project_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role', 30);                            // ProjectRole
            $table->foreignId('assigned_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('unassigned_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'user_id', 'role']);
            $table->index(['tenant_id', 'user_id']);               // "my projects" for consultants
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_assignments');
    }
};
