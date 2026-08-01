<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Period targets: the indicator carries the target *type*, this table
        // carries the numbers per period. Values are decimal(18,4) — ratios
        // and rates need the extra places.
        Schema::create('indicator_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('indicator_id')->constrained()->cascadeOnDelete();
            $table->string('period_type', 20);                     // MeasurementFrequency vocabulary
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('target_value', 18, 4);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['indicator_id', 'period_start', 'period_end']);
            $table->index(['tenant_id', 'indicator_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indicator_targets');
    }
};
