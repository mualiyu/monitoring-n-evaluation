<?php

namespace App\Notifications\Inspections;

use App\Models\SiteInspection;
use App\Notifications\Concerns\NotificationCategories;
use App\Notifications\Concerns\RespectsPreferences;
use App\Support\InstanceTime;
use App\Support\SurfaceUrl;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "The visit you were down for on Thursday is off, and here is why."
 *
 * This one is mundane and it is the reason the cancellation reason is
 * mandatory: an inspector who is not told drives to a site that has been
 * cancelled. The reason travels with the notice, so the monitor learns the
 * access road is flooded rather than that the platform changed its mind.
 *
 * NOT queued itself: it is sent from inside a queued, tenant-aware job.
 */
class InspectionCancelled extends Notification
{
    use RespectsPreferences;

    public function __construct(
        private readonly SiteInspection $inspection,
        private readonly ?string $reason = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->preferredChannels($notifiable, ['database', 'mail']);
    }

    public function notificationCategory(): string
    {
        return NotificationCategories::INSPECTIONS;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $project = $this->inspection->project;

        return (new MailMessage)
            ->subject(__('Site visit cancelled: :title', ['title' => $project->title]))
            ->greeting(__('Hello,'))
            ->line(__('The :type of :title (:reference), scheduled for :date, has been cancelled.', [
                'type' => mb_strtolower($this->inspection->type->label()),
                'title' => $project->title,
                'reference' => $project->reference,
                'date' => InstanceTime::local($this->inspection->scheduled_date)->translatedFormat('j M Y'),
            ]))
            ->line(__('Reason: :reason', [
                'reason' => $this->reason ?? $this->inspection->cancellation_reason ?? '—',
            ]))
            ->action(__('Open the workspace'), SurfaceUrl::base($this->inspection->tenant));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'inspection.cancelled',
            'inspection_ulid' => $this->inspection->ulid,
            'inspection_type' => $this->inspection->type->value,
            'scheduled_date' => $this->inspection->scheduled_date->toDateString(),
            'reason' => $this->reason ?? $this->inspection->cancellation_reason,
            'project_ulid' => $this->inspection->project->ulid,
            'project_reference' => $this->inspection->project->reference,
            'project_title' => $this->inspection->project->title,
            'tenant_id' => $this->inspection->tenant_id,
        ];
    }
}
