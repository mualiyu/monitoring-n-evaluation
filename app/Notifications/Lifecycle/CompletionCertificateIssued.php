<?php

namespace App\Notifications\Lifecycle;

use App\Models\Certificate;
use App\Notifications\Concerns\NotificationCategories;
use App\Notifications\Concerns\RespectsPreferences;
use App\Support\InstanceTime;
use App\Support\SurfaceUrl;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "A completion certificate has been issued." Sent to the MDA administrator
 * and to state oversight, because certification is the signature the state
 * answers for: it releases the works to the public and unlocks payment.
 *
 * Oversight is told at the same moment as the MDA, not through a nightly
 * roll-up — the manual's rewards-and-sanctions process depends on the
 * secretariat seeing completions as they happen rather than in arrears.
 *
 * The link is surface-aware: an MDA admin is sent to the workspace and an
 * oversight user to the state register, because a URL on the wrong subdomain
 * is a dead end for whoever receives it.
 *
 * NOT queued itself: it is sent from inside a queued, tenant-aware job.
 */
class CompletionCertificateIssued extends Notification
{
    use RespectsPreferences;

    public function __construct(
        private readonly Certificate $certificate,
        private readonly bool $forOversight = false,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->preferredChannels($notifiable, ['database', 'mail']);
    }

    public function notificationCategory(): string
    {
        return NotificationCategories::PROJECTS;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $project = $this->certificate->project;

        $message = (new MailMessage)
            ->subject(__(':type issued: :title', [
                'type' => $this->certificate->type->shortLabel(),
                'title' => $project->title,
            ]))
            ->greeting(__('Hello,'))
            ->line(__(':type has been issued for :title (:reference).', [
                'type' => $this->certificate->type->label(),
                'title' => $project->title,
                'reference' => $project->reference,
            ]))
            ->line(__('Certificate number :number, issued :date.', [
                'number' => $this->certificate->reference,
                // The instance's wall clock, not UTC.
                'date' => InstanceTime::local($this->certificate->issued_at)->translatedFormat('j M Y'),
            ]));

        if ($this->certificate->defects_liability_ends_on !== null) {
            $message->line(__('The defects-liability period runs until :date.', [
                'date' => InstanceTime::local($this->certificate->defects_liability_ends_on)->translatedFormat('j M Y'),
            ]));
        }

        return $message->action(
            $this->forOversight ? __('Open the state register') : __('Open the workspace'),
            $this->forOversight
                ? SurfaceUrl::oversight('/certificates')
                : SurfaceUrl::base($this->certificate->tenant).'/certificates',
        );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'certificate.issued',
            'certificate_ulid' => $this->certificate->ulid,
            'certificate_reference' => $this->certificate->reference,
            'certificate_type' => $this->certificate->type->value,
            'project_ulid' => $this->certificate->project->ulid,
            'project_title' => $this->certificate->project->title,
            'project_reference' => $this->certificate->project->reference,
            'issued_at' => $this->certificate->issued_at->toDateTimeString(),
            'defects_liability_ends_on' => $this->certificate->defects_liability_ends_on?->toDateString(),
            'tenant_id' => $this->certificate->tenant_id,
        ];
    }
}
