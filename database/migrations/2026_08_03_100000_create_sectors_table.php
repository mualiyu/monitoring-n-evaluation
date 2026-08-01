<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Global reference taxonomy — deliberately NO tenant_id: a state-wide
        // dashboard can only aggregate 40 MDAs if they share one sector axis.
        // No soft deletes either: retirement is is_active = false, so a
        // restrictOnDelete FK from projects can never be orphaned.
        Schema::create('sectors', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('sectors')->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sectors');
    }
};
