<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // GLOBAL, for the same reason its parent is: a response inherits the
        // feedback's tenancy anchor (the project) and has none of its own.
        //
        // The response thread is what turns a complaints box into
        // accountability: "we inspected on 4 March, works resumed" published
        // under the complaint is the whole point of the portal. `is_public`
        // exists because not every reply belongs on a public page — an
        // internal note to the M&E officer is still part of the thread.
        Schema::create('feedback_responses', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            // cascadeOnDelete: a response has no meaning without the feedback
            // it answers. Both are soft-deleted in practice, so this only
            // fires on a deliberate hard purge.
            $table->foreignId('feedback_id')->constrained('feedback')->cascadeOnDelete();

            $table->text('body');

            // restrictOnDelete: an official response on a public page must keep
            // naming the office that gave it — a user row that answered the
            // public cannot be erased out from under it.
            $table->foreignId('responded_by_id')->constrained('users')->restrictOnDelete();

            // Whether this reply is shown under the feedback on the portal.
            // Only ever rendered when the PARENT feedback is published too —
            // two gates, because an internal note under a pending complaint is
            // exactly the thing that must not leak.
            $table->boolean('is_public')->default(true);

            $table->timestamp('responded_at');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['feedback_id', 'is_public']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_responses');
    }
};
