<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The workspace register (plan §1/§11) shows an MDA by more than its
        // subdomain: who to call when a return is late, which sector it
        // reports into, when the state onboarded it and — if it is switched
        // off — when and why. Deactivation is REVERSIBLE and destroys nothing:
        // `is_active` already exists and ResolveTenant refuses the subdomain,
        // so the record, its projects and its audit trail all survive intact.
        //
        // No after(): the clause is a no-op on SQLite and would make column
        // order differ between the test and production databases.
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('short_name', 60)->nullable();
            // Global reference data (sectors carries no tenant_id): the
            // state-wide register groups MDAs on the same axis the portfolio
            // aggregates on. nullOnDelete, not restrict — retiring a sector
            // must never be blocked by a workspace that referenced it.
            $table->foreignId('sector_id')->nullable()->constrained('sectors')->nullOnDelete();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->timestamp('onboarded_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->string('deactivated_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sector_id');
            $table->dropColumn([
                'short_name', 'contact_name', 'contact_email', 'contact_phone',
                'onboarded_at', 'deactivated_at', 'deactivated_reason',
            ]);
        });
    }
};
