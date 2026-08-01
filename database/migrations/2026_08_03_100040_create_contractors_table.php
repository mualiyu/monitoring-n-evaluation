<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The state's vendor registry — GLOBAL BY DESIGN, no tenant_id.
        // A per-MDA contractor table would let a firm blacklisted by Works
        // keep winning contracts in Health. What stays tenant-owned is the
        // relationship: `contracts` carries tenant_id, so who engaged whom for
        // how much is never cross-visible.
        Schema::create('contractors', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('name');
            $table->string('rc_number', 40)->nullable()->unique();
            $table->string('type', 30)->default('contractor');    // FirmType
            $table->string('category', 60)->nullable();           // civil works, consultancy, supply…
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_blacklisted')->default(false);
            $table->text('blacklist_reason')->nullable();
            $table->decimal('performance_score', 4, 2)->nullable(); // 0.00–100.00, oversight-computed
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            // Provenance only — NEVER a scope key. See the Contractor docblock.
            $table->foreignId('created_by_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'is_blacklisted']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contractors');
    }
};
