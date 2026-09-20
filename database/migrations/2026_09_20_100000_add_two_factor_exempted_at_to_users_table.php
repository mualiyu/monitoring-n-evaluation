<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Explicit, audited release from the role-mandated 2FA enrolment
            // (auth-surfaces.md §4). Written only by ExemptFromTwoFactor; a
            // set value means RequireTwoFactor never forces this account into
            // setup. It does not disable a second factor the user has enrolled.
            $table->timestamp('two_factor_exempted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('two_factor_exempted_at');
        });
    }
};
