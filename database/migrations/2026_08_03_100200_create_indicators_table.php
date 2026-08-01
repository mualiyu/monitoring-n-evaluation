<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Full-width indicator definition sheet, frozen in Phase 1 even though
        // the Results Framework module (Actions, validation workflow, logframe
        // UI) is a separate deliverable — freezing the shape now is what
        // prevents a readings migration later.
        //
        // Baseline is MANDATORY AT ACTIVATION, not by NOT NULL: a NOT NULL
        // baseline forces a placeholder into every half-drafted indicator, and
        // a fabricated zero baseline is worse data quality than an explicit
        // null. ActivateIndicator enforces value + date + source.
        Schema::create('indicators', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            // Nullable: MDA-programme indicators exist that belong to no
            // single project.
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            // Deliberately unconstrained: the result_frameworks table arrives
            // in Phase 2 and attaches without migrating readings.
            $table->unsignedBigInteger('result_framework_id')->nullable();
            $table->string('tier', 20)->nullable();                // pdo | intermediate | output
            $table->string('name');
            $table->text('definition')->nullable();
            $table->string('unit', 20);                            // IndicatorUnit
            $table->string('measurement_frequency', 20);           // MeasurementFrequency — deadline engine v1
            $table->text('data_source')->nullable();
            $table->text('means_of_verification')->nullable();
            $table->foreignId('responsible_collector_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('responsible_collector_text')->nullable(); // role/office when it is not a platform user
            $table->decimal('baseline_value', 18, 4)->nullable();
            $table->date('baseline_date')->nullable();
            $table->string('baseline_source')->nullable();
            $table->string('target_type', 30);                     // TargetType (values live in indicator_targets)
            $table->text('smart_justification')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'project_id']);
            $table->index(['tenant_id', 'measurement_frequency']); // deadline sweep
            $table->index(['tenant_id', 'is_active']);
            $table->index('result_framework_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indicators');
    }
};
