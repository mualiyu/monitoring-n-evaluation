<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // RESERVED AND UNUSED IN PHASE 1. Contractor self-service accounts are
        // an open question (PROJECT_PLAN §9); the column is added now so that
        // answering "yes" later does not mean an ALTER on `users`. Nothing
        // reads or writes it, and no relation is defined until it is decided.
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('contractor_id')->nullable()->after('phone')
                ->constrained('contractors')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contractor_id');
        });
    }
};
