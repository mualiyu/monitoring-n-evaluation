<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned. Step 5 of the monitoring lifecycle (digest §8): the
        // Project Completion Certificate that rests on a final inspection.
        //
        // TWO TYPES, NOT ONE (CertificateType): practical completion hands the
        // works over and starts the defects-liability period; final completion
        // closes that period and releases retention. A single "completed"
        // artifact cannot express the months in between, which is exactly the
        // window a state needs in order to make a contractor come back.
        //
        // `site_inspection_id` IS DELIBERATELY UNCONSTRAINED: the inspections
        // module owns that table and lands independently, so this column is a
        // nullable soft reference read defensively (Schema::hasTable) rather
        // than a foreign key that would make the two migrations a package.
        // The reference is snapshotted here because a certificate must name
        // the inspection it rests on even if that record is later corrected.
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();

            $table->string('type', 24);         // CertificateType
            $table->string('reference', 64);

            $table->unsignedBigInteger('site_inspection_id')->nullable();

            $table->text('narrative')->nullable();
            // Practical completion + the contract's defects-liability period.
            // Nullable: a final-completion certificate has nothing after it.
            $table->date('defects_liability_ends_on')->nullable();

            $table->foreignId('issued_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at');

            // Revocation, not deletion: a certificate that was served and then
            // withdrawn is a fact an auditor must still be able to read
            // (rules/security.md — the audit trail is append-only).
            $table->foreignId('revoked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();

            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // A certificate number is a citable reference; two rows sharing one
            // inside an MDA would make "certificate WKS/PC/2026/0007" ambiguous
            // in correspondence that outlives this platform.
            $table->unique(['tenant_id', 'reference']);
            $table->index(['tenant_id', 'project_id', 'type']);
            $table->index(['tenant_id', 'issued_at']);
            $table->index(['tenant_id', 'type']);
            $table->index('site_inspection_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
