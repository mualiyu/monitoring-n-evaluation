<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned. Location lives in project_locations and funding in
        // project_funding_sources — both left this table deliberately, because
        // projects are multi-site and co-funded far more often than not.
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('reference', 40);                       // MDA project code
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('goal')->nullable();                      // scope-definition step
            $table->text('objectives')->nullable();
            $table->foreignId('sector_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);                            // ProjectType
            $table->string('status', 20)->default('draft');        // ProjectStatus
            // The MDA supervising execution is often not the procuring MDA
            // (Works supervises for Health; a PIU supervises donor projects).
            // Statutory metadata ONLY — it grants no cross-tenant access.
            $table->foreignId('supervising_agency_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('supervising_agency_name')->nullable(); // non-tenant supervisor
            $table->decimal('budget_allocation', 18, 2)->nullable(); // appropriation
            $table->string('budget_code', 60)->nullable();           // finance linkage (Phase 4)
            $table->decimal('contract_value_total', 18, 2)->nullable()
                ->comment('DERIVED CACHE: Σ active contracts + variations. Source of truth is `contracts`. Written only by AwardContract / RecordContractVariation inside their transaction.');
            $table->decimal('expenditure_to_date', 18, 2)->default(0);
            $table->decimal('physical_progress', 5, 2)->default(0);   // human-attested roll-up
            $table->date('start_date')->nullable();
            $table->date('expected_end_date')->nullable();          // planned
            $table->date('revised_end_date')->nullable();           // variation / approved extension
            $table->date('actual_end_date')->nullable();
            $table->date('post_completion_review_due_at')->nullable();
            // Set once, when physical progress crosses the mid-term trigger:
            // mid-term evaluation is an event, never a lifecycle status.
            $table->timestamp('mid_term_flagged_at')->nullable();
            // Phase 3 portal gate. PublishProject writes it; no route reads it.
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by_id')->nullable()->constrained('users')->nullOnDelete();
            // Drives per-project report obligations (progress-reporting §0).
            // Null = the instance default. Distinct from an indicator's
            // measurement_frequency, which governs readings, not reports.
            $table->string('reporting_frequency', 20)->nullable();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'reference']);
            $table->index(['tenant_id', 'status']);                 // portfolio list + dashboard
            $table->index(['tenant_id', 'sector_id']);
            $table->index(['tenant_id', 'expected_end_date']);      // overdue detection
            $table->index('status');                                // state-wide aggregate
            $table->index(['tenant_id', 'post_completion_review_due_at']);
            $table->index('published_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
