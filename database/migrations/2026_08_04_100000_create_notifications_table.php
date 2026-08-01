<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Laravel's database notification channel. Deliberately NOT
        // tenant-owned: a notification belongs to a person, and the same
        // person may hold memberships in several MDAs. What scopes it is the
        // record it points at — the notification data carries the project's
        // ulid and the tenant that raised it, and every link it renders goes
        // through a tenant-resolved route that re-checks the policy.
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
