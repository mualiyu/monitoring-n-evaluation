<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ward codes are only unique within their LGA.
        Schema::create('wards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lga_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['lga_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wards');
    }
};
