<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type')->default('ministry');
            $table->json('branding')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Instance-level and tenant-level settings are separate tables: a
        // single table with nullable tenant_id cannot enforce uniqueness for
        // instance rows (SQL UNIQUE treats NULLs as distinct) and would force
        // manual tenant_id clauses, which the tenancy rules ban.
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('group', 50)->default('general');
            $table->string('key', 100);
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['group', 'key']);
        });

        Schema::create('tenant_settings', function (Blueprint $table) {
            $table->id();
            // cascade (not restrict) is deliberate: settings are config, not
            // domain records — they carry no audit value once the MDA is gone
            // and must never block a sanctioned tenant force-delete.
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('group', 50)->default('general');
            $table->string('key', 100);
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'group', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('tenants');
    }
};
