<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Anchor of the 2FA enrolment grace period; stamped by AssignRole
            // when a 2FA-required role is granted, cleared when the last one
            // is removed.
            $table->timestamp('two_factor_required_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_required_at', 'last_login_ip']);
        });
    }
};
