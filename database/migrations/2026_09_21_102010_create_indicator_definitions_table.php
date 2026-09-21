<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The state indicator library — GLOBAL REFERENCE DATA, deliberately
        // WITHOUT tenant_id, exactly like sectors and funding_sources.
        //
        // This is the manual's "predetermined indicator list" (digest §4): the
        // secretariat consolidates every MDA's annual return against ONE list,
        // which is only possible if "classrooms completed" means the same
        // thing, in the same unit, at the same frequency, in every ministry.
        // A per-tenant copy of the library would make the state report a pile
        // of incomparable numbers — which is the problem the manual's
        // indicator retreat exists to fix.
        //
        // A definition is a TEMPLATE, never a measurement: it carries no
        // baseline, no target and no reading. An MDA instantiates it into its
        // own tenant-scoped `indicators` row, which is where the numbers live.
        //
        // Retirement is is_active = false, never deletion (as with sectors):
        // instantiated indicators hold a restrictOnDelete FK back here, and a
        // library entry that vanished would orphan the definition of a figure
        // already published.
        Schema::create('indicator_definitions', function (Blueprint $table) {
            $table->id();
            // Stable public handle used by seeders and by MDAs quoting the
            // state list ("EDU-ENR-01"). Unique because it is the identity.
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->text('definition')->nullable();
            // The manual's matrices carry an "indicator focus" column next to
            // the definition — the outcome area the measure speaks to.
            $table->string('focus')->nullable();
            // Optional sector so the library filters the way the state's MTSS
            // is organised. Global reference table to global reference table.
            $table->foreignId('sector_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('unit', 20);                            // IndicatorUnit
            $table->string('default_measurement_frequency', 20);   // MeasurementFrequency
            $table->string('default_target_type', 30);             // TargetType
            $table->string('default_tier', 20)->nullable();        // IndicatorTier
            $table->text('data_source')->nullable();
            $table->text('means_of_verification')->nullable();
            // A role or office, never a user: the library is state-wide and a
            // named officer belongs to one MDA.
            $table->string('responsible_collector_text')->nullable();
            $table->text('smart_statement')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'name']);
            $table->index('sector_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indicator_definitions');
    }
};
