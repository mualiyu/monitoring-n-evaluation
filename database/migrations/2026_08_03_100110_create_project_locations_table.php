<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned child of projects. Projects are frequently multi-site
        // ("N facilities across M LGAs") and Phase 2 inspections FK to a site;
        // single-site projects carry exactly one row with is_primary = true.
        //
        // No unique index on (project_id, is_primary): MySQL has no partial
        // unique index, and the naive composite would wrongly cap non-primary
        // sites at one. SetPrimaryProjectLocation enforces single-primary in a
        // transaction instead, covered by a test.
        Schema::create('project_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('site_name')->nullable();                // "Ward 3 PHC", "Km 4–7 alignment"
            $table->text('description')->nullable();
            $table->foreignId('lga_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('ward_id')->nullable()->constrained()->restrictOnDelete();
            // NOT spatial columns — decimal keeps the suite SQLite-runnable.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'lga_id']);                 // map / LGA aggregates
            $table->index(['project_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_locations');
    }
};
