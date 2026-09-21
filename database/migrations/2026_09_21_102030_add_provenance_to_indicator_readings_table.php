<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who measured it, who published it, and why it came back.
        //
        // recorded_by_id is the column the whole data-quality separation rests
        // on. submitted_by_id alone is not enough: an M&E officer can submit a
        // figure a field monitor recorded, and a reviewer who happens to be
        // the RECORDER would then be clearing their own measurement while
        // passing the submitter check. The manual gives validation to a third
        // party (the Bureau of Statistics) exactly to break that loop, so the
        // guard needs both identities.
        Schema::table('indicator_readings', function (Blueprint $table) {
            $table->foreignId('recorded_by_id')->nullable()->after('notes')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('published_by_id')->nullable()->after('published_at')
                ->constrained('users')->nullOnDelete();
            // The CURRENT rejection state, for the recorder's screen. The
            // history of every rejection lives in indicator_reading_events.
            $table->foreignId('rejected_by_id')->nullable()->after('published_by_id')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by_id');
            $table->text('rejection_reason')->nullable()->after('rejected_at');
        });
    }

    public function down(): void
    {
        Schema::table('indicator_readings', function (Blueprint $table) {
            $table->dropForeign(['recorded_by_id']);
            $table->dropForeign(['published_by_id']);
            $table->dropForeign(['rejected_by_id']);
            $table->dropColumn([
                'recorded_by_id', 'published_by_id', 'rejected_by_id',
                'rejected_at', 'rejection_reason',
            ]);
        });
    }
};
