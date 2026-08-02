<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // GLOBAL — deliberately no tenant_id (progress-reporting.md §1.1).
        // The statutory calendar is state-wide: every MDA reports against the
        // same windows, and the compliance league table is only meaningful if
        // the denominator is identical across MDAs. A per-tenant calendar
        // would make "which MDA was late" unanswerable.
        //
        // Status (upcoming|open|closed) is DERIVED from opens_at/closes_at vs
        // now. Storing it would need a cron to keep it true, and a stale
        // status column on a statutory deadline is a compliance defect.
        Schema::create('reporting_periods', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('code', 20)->unique();               // 2026-M03, 2026-Q1, 2026-H1, 2026-A
            $table->string('cadence', 12);                      // ReportingCadence
            $table->string('label');                            // "March 2026", "First Half 2026"
            $table->date('period_start');
            $table->date('period_end');
            $table->timestamp('opens_at');                      // submissions accepted from
            $table->timestamp('due_at');                        // statutory deadline
            $table->timestamp('closes_at')->nullable();         // hard close; null = stays open, late flagged
            $table->string('generated_by', 12)->default('system');
            $table->timestamps();

            // The natural key the generator upserts on, so a re-run converges
            // rather than duplicating the calendar.
            $table->unique(['cadence', 'period_start']);
            $table->index('due_at');
            $table->index(['cadence', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reporting_periods');
    }
};
