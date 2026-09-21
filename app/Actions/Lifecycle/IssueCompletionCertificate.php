<?php

declare(strict_types=1);

namespace App\Actions\Lifecycle;

use App\Actions\Lifecycle\Concerns\FindsFinalInspection;
use App\Actions\Lifecycle\Concerns\RendersLifecyclePdf;
use App\Actions\Projects\TransitionProjectStatus;
use App\Enums\CertificateType;
use App\Enums\ProjectStatus;
use App\Exceptions\Lifecycle\LifecycleRuleViolation;
use App\Jobs\Lifecycle\NotifyCertificateIssued;
use App\Models\Certificate;
use App\Models\Project;
use App\Models\User;
use App\Support\SettingsRepository;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Issue a completion certificate — step 5 of the monitoring lifecycle (digest
 * §8) and the most consequential signature in the platform: it releases the
 * works to the public, starts (or ends) the defects-liability period, and
 * unlocks payment.
 *
 * THE PROJECT STATUS IS NOT WRITTEN HERE. Certification moves the project to
 * `certified` through App\Actions\Projects\TransitionProjectStatus — the one
 * writer of that column — so the transition table, the `projects.certify`
 * permission, the typed status ledger and the post-completion review clock all
 * apply exactly as they do everywhere else. A second writer for `status` would
 * make every guard in that Action optional.
 *
 * Everything is one transaction: the certificate row, its rendered PDF and the
 * project transition. A certificate that exists for a project the lifecycle
 * refused to certify would be a signed document with nothing behind it.
 *
 * `monitoring.require_final_inspection_for_certification`: this Action refuses
 * certification when the setting is on and no completed final inspection is
 * recorded. TransitionProjectStatus asks the same question of the same soft
 * dependency (FindsFinalInspection) before it moves the status, so the guard
 * holds on both halves of the act and neither can be reached around the
 * other.
 */
class IssueCompletionCertificate
{
    use FindsFinalInspection;
    use RendersLifecyclePdf;

    public function __invoke(
        Project $project,
        CertificateType $type,
        User $actor,
        ?string $narrative = null,
        ?CarbonInterface $defectsLiabilityEndsOn = null,
    ): Certificate {
        Gate::forUser($actor)->authorize('issue', [Certificate::class, $project]);

        $this->assertPreconditions($project, $type, $defectsLiabilityEndsOn);

        $project->loadMissing('tenant');
        $tenant = $project->tenant;
        $narrative = $narrative === null ? null : trim($narrative);
        $inspection = $this->finalInspectionFor($project);
        $issuedAt = CarbonImmutable::now();

        $certificate = DB::transaction(function () use (
            $project, $tenant, $type, $actor, $narrative, $defectsLiabilityEndsOn, $inspection, $issuedAt
        ): Certificate {
            $certificate = new Certificate([
                'project_id' => $project->id,
                'type' => $type,
                // The inspection the certificate rests on, snapshotted by id.
                // Null is legitimate: an instance that does not require a
                // final inspection certifies on the completion attestation.
                'site_inspection_id' => $inspection['id'] ?? null,
                'narrative' => $narrative === '' ? null : $narrative,
                'defects_liability_ends_on' => $type->opensDefectsLiability()
                    ? $defectsLiabilityEndsOn
                    : null,
                'created_by_id' => $actor->id,
            ]);

            // Explicit, like ProjectStatusEvent's: the project is the
            // authority on whose record this is, and a console caller has no
            // bound tenant for the auto-fill to read.
            $certificate->tenant_id = $project->tenant_id;

            // forceFill: the reference and the signature are deliberately not
            // fillable — a certificate number a form payload could choose is a
            // number that can be made to collide with one already in force.
            $certificate->forceFill([
                'reference' => Certificate::mintReference($tenant, $type, $issuedAt),
                'issued_by_id' => $actor->id,
                'issued_at' => $issuedAt,
            ])->save();

            $this->attachRenderedPdf(
                $certificate,
                'certificate',
                'pdf.completion-certificate',
                [
                    'certificate' => $certificate,
                    'project' => $project,
                    'issuedBy' => $actor,
                    'inspection' => $inspection,
                ],
                __('Completion certificate :reference', ['reference' => $certificate->reference]),
                $actor,
                $tenant,
            );

            // The chokepoint, not a status write. A project already certified
            // (the second certificate of the pair) is not asked to move
            // again — the lifecycle has one certification state, and asking
            // for a transition that does not exist would refuse a legitimate
            // final-completion certificate.
            if ($project->status !== ProjectStatus::Certified) {
                app(TransitionProjectStatus::class)(
                    $project,
                    ProjectStatus::Certified,
                    $actor,
                    __('Completion certificate :reference issued.', ['reference' => $certificate->reference]),
                );
            }

            return $certificate;
        });

        NotifyCertificateIssued::dispatch($certificate->id);

        return $certificate;
    }

    private function assertPreconditions(
        Project $project,
        CertificateType $type,
        ?CarbonInterface $defectsLiabilityEndsOn,
    ): void {
        // Certification attests that the works are finished. `completed` is
        // the state that records that attestation (and TransitionProjectStatus
        // only accepts it at 100% physical progress); `certified` is here
        // because the second certificate of the pair is issued against an
        // already-certified project.
        if (! in_array($project->status, [ProjectStatus::Completed, ProjectStatus::Certified], true)) {
            throw LifecycleRuleViolation::certificationBeforeCompletion($project->status);
        }

        // The Phase 2 rule, switched by the instance: a certificate that rests
        // on nobody having visited the site is the failure this whole module
        // exists to prevent.
        if (app(SettingsRepository::class)->bool('monitoring', 'require_final_inspection_for_certification', false)
            && ! $this->hasFinalInspection($project)) {
            throw LifecycleRuleViolation::certificationRequiresFinalInspection();
        }

        $active = Certificate::query()
            ->active()
            ->where('project_id', $project->id)
            ->get(['id', 'type']);

        if ($active->contains(fn (Certificate $certificate): bool => $certificate->type === $type)) {
            throw LifecycleRuleViolation::certificateAlreadyIssued($type);
        }

        // Final completion closes the window practical completion opens, so it
        // cannot come first — a state that released retention before the
        // defects period ran has no lever left over the contractor.
        if ($type === CertificateType::FinalCompletion
            && ! $active->contains(fn (Certificate $certificate): bool => $certificate->type === CertificateType::PracticalCompletion)) {
            throw LifecycleRuleViolation::finalCompletionBeforePracticalCompletion();
        }

        if ($type->opensDefectsLiability()
            && $defectsLiabilityEndsOn !== null
            && $defectsLiabilityEndsOn->isBefore(CarbonImmutable::now()->startOfDay())) {
            throw LifecycleRuleViolation::defectsLiabilityEndsBeforeIssue();
        }
    }
}
