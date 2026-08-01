<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned: the contractor registry is global, the engagement is
        // not. This is where the tenancy line sits — every MDA sees every
        // firm, no MDA sees another's contracts.
        //
        // `sum` is the ORIGINAL award and is immutable once awarded; a
        // revision is a NEW row carrying varies_contract_id + variation_reason
        // (the manual's amendment-register pattern), so superseded values stay
        // readable. Revised value = sum + Σ variations, exposed as an accessor.
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('contractor_id')->constrained()->restrictOnDelete();
            $table->string('contract_number', 60);
            $table->string('type', 20)->default('works');         // ContractType
            $table->string('status', 20)->default('awarded');     // ContractStatus
            $table->decimal('sum', 18, 2);                        // original award — immutable
            $table->text('scope_of_works');                       // statutory: quoted in the commencement notice
            $table->date('award_date');
            $table->date('commencement_date')->nullable();
            $table->unsignedSmallInteger('duration_days')->nullable();
            $table->date('expected_completion_date')->nullable();
            $table->decimal('retention_percentage', 5, 2)->nullable();
            $table->foreignId('varies_contract_id')->nullable()->constrained('contracts')->restrictOnDelete();
            $table->text('variation_reason')->nullable();         // required when varies_contract_id is set
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'contract_number']);
            $table->index(['tenant_id', 'project_id']);
            $table->index('contractor_id');
            $table->index('varies_contract_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
