<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned pivot. Donor + counterpart funding is near-universal on
        // multilateral projects, and the scalar projects.funding_source_id it
        // replaces would have forced every future report to lie about one of
        // them. Filtering by funding source now costs a join — accepted;
        // misattributed donor money is a reporting failure, a join is not.
        Schema::create('project_funding_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('funding_source_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 18, 2)->nullable();
            $table->decimal('percentage', 5, 2)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['project_id', 'funding_source_id']);
            $table->index(['tenant_id', 'funding_source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_funding_sources');
    }
};
