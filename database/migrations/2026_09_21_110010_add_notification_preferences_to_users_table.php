<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preference-aware notifications (rules/architecture.md). A column
        // rather than a table: the payload is a handful of booleans read on
        // every send, it is always read whole and never queried across users,
        // and a join per recipient per notification is the cost a table would
        // add for nothing.
        //
        // Shape: { "<category>": { "<channel>": false } }. ABSENT MEANS ON —
        // a new category or a new channel is delivered by default, so adding
        // one can never silently mute people who never opted out of it.
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_preferences')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_preferences');
        });
    }
};
