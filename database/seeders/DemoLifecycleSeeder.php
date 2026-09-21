<?php

namespace Database\Seeders;

use App\Enums\CertificateType;
use App\Enums\CommencementNoticeStatus;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Models\Certificate;
use App\Models\CommencementNotice;
use App\Models\Contract;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Support\SettingsRepository;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Commencement notices and completion certificates across the demo portfolio.
 *
 * The spread is deliberate rather than random: a screen that has only ever
 * rendered the happy path has not been tested. So the demo carries a notice
 * acknowledged by the contractor, one served late, one still outstanding and
 * past its statutory window (which is what the overdue sweep and the red stat
 * tile exist for), a project certified at practical completion inside its
 * defects-liability period, and one carried through to final completion.
 *
 * Everything is created inside `CurrentTenant::runAs($tenant, …)`, so
 * BelongsToTenant fills tenant_id. Nothing here writes tenant_id by hand and
 * nothing queries by it.
 *
 * FIXTURES, NOT ACTIONS: like DemoProgressReportSeeder, this states where
 * records ARE rather than driving them there — running the real Actions would
 * render PDFs and fire queued notifications during `migrate:fresh --seed`, and
 * would need the project status chokepoint to accept a demo actor.
 *
 * Depends on: DemoTenantSeeder, DemoProjectSeeder.
 */
class DemoLifecycleSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $current = app(CurrentTenant::class);
        $current->forget();

        foreach (Tenant::query()->get() as $tenant) {
            $admin = $this->admin($tenant);

            if ($admin === null) {
                continue;
            }

            $current->runAs($tenant, function () use ($tenant, $admin): void {
                $this->notices($tenant, $admin);
                $this->certificates($tenant, $admin);
            });
        }

        $current->forget();
    }

    /**
     * One notice per original award, cycled through the three states the
     * screen has to render.
     */
    private function notices(Tenant $tenant, User $admin): void
    {
        $noticeDays = app(SettingsRepository::class)->int('monitoring', 'commencement_notice_days', 3);

        $contracts = Contract::query()
            ->with('project')
            ->whereNull('varies_contract_id')
            ->whereHas('project', fn ($project) => $project
                ->whereNotIn('status', [ProjectStatus::Draft, ProjectStatus::Cancelled]))
            ->orderBy('id')
            ->get();

        foreach ($contracts->values() as $index => $contract) {
            if (CommencementNotice::query()->where('contract_id', $contract->id)->exists()) {
                continue;
            }

            $project = $contract->project;
            $project->setRelation('tenant', $tenant);

            $notice = new CommencementNotice(CommencementNotice::snapshotOf($contract, $project, $noticeDays));
            $notice->reference = Str::upper($contract->contract_number).'/CN';
            $notice->created_by_id = $admin->id;
            $notice->tenant_id = $project->tenant_id;

            // 0 → acknowledged, 1 → served late, 2 → still outstanding and
            // overdue, 3 → served on time.
            match ($index % 4) {
                0 => $this->acknowledged($notice, $admin),
                1 => $this->servedLate($notice, $admin),
                2 => $this->outstanding($notice),
                default => $this->served($notice, $admin),
            };

            $notice->save();
        }
    }

    private function acknowledged(CommencementNotice $notice, User $admin): void
    {
        $this->served($notice, $admin);

        $notice->forceFill([
            'status' => CommencementNoticeStatus::Acknowledged,
            'acknowledged_by_id' => $admin->id,
            'acknowledged_by_contractor_at' => $notice->due_at->addDays(2),
            'acknowledgement_note' => 'Signed hard copy returned by the site engineer.',
        ]);
    }

    private function served(CommencementNotice $notice, User $admin): void
    {
        $notice->forceFill([
            'status' => CommencementNoticeStatus::Issued,
            'issued_by_id' => $admin->id,
            'issued_at' => $notice->due_at->subDay(),
            'issued_late' => false,
            'instructions' => 'Site handover on commencement; weekly returns to the supervising officer.',
        ]);
    }

    private function servedLate(CommencementNotice $notice, User $admin): void
    {
        $notice->forceFill([
            'status' => CommencementNoticeStatus::Issued,
            'issued_by_id' => $admin->id,
            'issued_at' => $notice->due_at->addDays(9),
            'issued_late' => true,
        ]);
    }

    /** Never served, and the window closed — what the overdue sweep finds. */
    private function outstanding(CommencementNotice $notice): void
    {
        $notice->forceFill([
            'status' => CommencementNoticeStatus::Pending,
            'due_at' => CarbonImmutable::now()->subDays(6)->toDateString(),
            'issued_by_id' => null,
            'issued_at' => null,
        ]);
    }

    /**
     * Certificates for the projects the project seeder already left in a
     * certified or closed state, so the register is not empty and the
     * lifecycle panel has something to show.
     */
    private function certificates(Tenant $tenant, User $admin): void
    {
        $projects = Project::query()
            ->whereIn('status', [ProjectStatus::Certified, ProjectStatus::Closed])
            ->orderBy('id')
            ->get();

        foreach ($projects->values() as $index => $project) {
            if (Certificate::query()->where('project_id', $project->id)->exists()) {
                continue;
            }

            $issuedAt = CarbonImmutable::instance(
                $project->actual_end_date?->addWeeks(2) ?? CarbonImmutable::now()->subMonths(2)
            );

            $practical = $this->certificate(
                $project,
                CertificateType::PracticalCompletion,
                $tenant,
                $admin,
                $issuedAt,
                // Half the demo set sits inside a live defects-liability
                // period; the other half has run out, which is what makes a
                // final-completion certificate legible below.
                $index % 2 === 0
                    ? CarbonImmutable::now()->addMonths(5)
                    : CarbonImmutable::now()->subMonths(1),
            );

            // Closed projects have been all the way through: the defects
            // window ran out and retention was released.
            if ($project->status === ProjectStatus::Closed) {
                $this->certificate(
                    $project,
                    CertificateType::FinalCompletion,
                    $tenant,
                    $admin,
                    $practical->issued_at->addMonths(12),
                    null,
                );
            }
        }
    }

    private function certificate(
        Project $project,
        CertificateType $type,
        Tenant $tenant,
        User $admin,
        CarbonImmutable $issuedAt,
        ?CarbonImmutable $defectsEnd,
    ): Certificate {
        $certificate = new Certificate([
            'project_id' => $project->id,
            'type' => $type,
            'narrative' => 'Works inspected and accepted. Snags listed in the final inspection report have been made good.',
            'defects_liability_ends_on' => $defectsEnd?->toDateString(),
            'created_by_id' => $admin->id,
        ]);

        $certificate->tenant_id = $project->tenant_id;

        $certificate->forceFill([
            'reference' => Certificate::mintReference($tenant, $type, $issuedAt),
            'issued_by_id' => $admin->id,
            'issued_at' => $issuedAt,
        ])->save();

        return $certificate;
    }

    private function admin(Tenant $tenant): ?User
    {
        return User::query()
            ->where('email', Role::MdaAdmin->value.'@'.$tenant->slug.'.mne.test')
            ->first();
    }
}
