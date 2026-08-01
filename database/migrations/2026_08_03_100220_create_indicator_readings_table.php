<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Actual measurements. restrictOnDelete to indicators (unlike targets,
        // which cascade): a reading is reported data, so an indicator with
        // readings cannot be deleted out from under its own evidence.
        //
        // Validation is a distinct hop from submission and publication is a
        // further explicit act — nothing reaches a public surface by default.
        Schema::create('indicator_readings', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('indicator_id')->constrained()->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('actual_value', 18, 4);
            $table->string('source_type', 20);                     // ReadingSourceType
            $table->string('collection_method')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('draft');        // IndicatorReadingStatus
            $table->foreignId('submitted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('validated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'indicator_id', 'period_start']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indicator_readings');
    }
};
