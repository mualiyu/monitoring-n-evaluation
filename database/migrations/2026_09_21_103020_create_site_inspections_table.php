<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned (plan §4 "Monitoring lifecycle"). One site visit: when
        // it was planned, who went, where they actually stood, what they found
        // — and the manual's Field Trip Report, which every field visit must
        // produce (digest §3).
        //
        // The seven narrative columns ARE that report. They are columns rather
        // than a JSON blob or a free-text body for one reason: the manual's
        // section 5 is "comparison with earlier visits", and a platform that
        // cannot select the previous visit's findings alongside this one's
        // cannot support the section it is asked to print.
        Schema::create('site_inspections', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            // Multi-site projects are the norm ("12 PHCs across 4 LGAs"), so a
            // visit names the site it was at. Null = the project as a whole,
            // which is what a single-site project means.
            $table->foreignId('project_location_id')->nullable()->constrained()->nullOnDelete();
            // The instrument used, snapshotted by reference. Global table, so
            // no tenancy boundary is crossed. nullOnDelete rather than
            // restrict: templates are soft-deleted in practice, and a hard
            // purge must not take a decade of inspection records with it —
            // the RESPONSES keep their own copy of what was asked.
            $table->foreignId('inspection_checklist_template_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->string('type', 20);                        // InspectionType
            $table->string('status', 12)->default('scheduled'); // InspectionStatus
            $table->date('scheduled_date');
            $table->timestamp('conducted_at')->nullable();

            $table->foreignId('lead_inspector_id')->constrained('users')->restrictOnDelete();
            // The accompanying party, as names and designations. Free text and
            // not a join table, deliberately: half the people on a site visit
            // are not platform users (the contractor's engineer, the LGA
            // supervisor, a community representative) and the manual asks for
            // "people/groups met", not for a user list. Who is ACCOUNTABLE is
            // lead_inspector_id, and that is a real FK.
            $table->text('team')->nullable();

            // Where the inspector actually stood, from the browser's
            // geolocation API. Decimal strings, never floats: these are
            // printed on a government report and must round-trip unchanged —
            // the same rule project_locations follows.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('gps_accuracy_metres')->nullable();
            $table->timestamp('gps_captured_at')->nullable();
            // Distance from the project's recorded site, computed once at
            // capture against `inspections.geofence_metres`. Stored rather
            // than derived on read: the policy number can change, and a
            // historical report must keep the judgement made at the time.
            $table->unsignedInteger('geofence_distance_metres')->nullable();
            $table->boolean('geofence_breached')->default(false);

            $table->decimal('physical_progress_observed', 5, 2)->nullable();
            $table->string('outcome', 20)->nullable();         // InspectionOutcome
            // Named risks the inspector ticked. A JSON list of keys, not rows:
            // these are a fixed vocabulary read as a set, never joined on, and
            // a table of five booleans per inspection buys nothing.
            $table->json('risk_flags')->nullable();

            // --- Field Trip Report, the manual's seven sections (digest §3) ---
            $table->text('objectives')->nullable();
            $table->text('people_met')->nullable();
            $table->text('methods')->nullable();
            $table->text('findings')->nullable();
            $table->text('comparison_with_previous')->nullable();
            $table->text('conclusions')->nullable();
            $table->text('recommendations')->nullable();

            // --- The chain: who did what, and when ---
            $table->foreignId('scheduled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->foreignId('submitted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->foreignId('cancelled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            // The report deadline: conducted_at + `inspections.report_due_days`,
            // snapshotted at the visit so a later policy change cannot
            // retroactively make a filed report late. Exactly the reasoning
            // behind progress_reports.due_at.
            $table->timestamp('report_due_at')->nullable();
            $table->timestamp('report_overdue_notified_at')->nullable();
            $table->boolean('report_late')->default(false);

            $table->timestamp('autosaved_at')->nullable();

            // How this row came to exist: `manual` (an officer scheduled it)
            // or `system` (ProposeRoutineInspections). The board shows
            // proposals differently — an MDA must be able to tell what it
            // committed to from what the platform suggested.
            $table->string('generated_by', 12)->default('manual');
            // The idempotency key of the scheduling engine. Deterministic for
            // a system proposal ("routine:2026-10") and NULL for everything a
            // human scheduled — which is what makes the unique index below
            // safe: SQL treats NULLs as distinct, so manual visits never
            // collide with each other or with a proposal.
            $table->string('schedule_key', 40)->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The engine's guarantee, in the schema rather than in a sweep's
            // good behaviour: one system proposal per project per window, even
            // if the cron runs twice or two workers overlap.
            $table->unique(['tenant_id', 'project_id', 'schedule_key'], 'site_inspections_schedule_key_unique');

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'project_id', 'scheduled_date']);
            $table->index(['tenant_id', 'type', 'status']);
            $table->index(['tenant_id', 'status', 'report_due_at']);   // the overdue sweep
            $table->index(['tenant_id', 'outcome']);
            $table->index(['tenant_id', 'lead_inspector_id', 'status']); // "my visits"
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_inspections');
    }
};
