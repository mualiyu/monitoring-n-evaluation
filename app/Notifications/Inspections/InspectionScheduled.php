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
 * "You are down to inspect the Akure–Ilesa road on 4 October."
 *
 * NOT queued itself: it is sent from inside a queued, tenant-aware job.
 */
class InspectionScheduled extends Notification
{
    use RespectsPreferences;

    public function __construct(private readonly SiteInspection $inspection) {}

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

        $message = (new MailMessage)
            ->subject(__(':type scheduled for :date', [
                'type' => $this->inspection->type->label(),
                // The instance's wall clock, not UTC: a visit on the 4th that
                // an inspector is told is "3 October 23:00" is a visit they
                // turn up for on the wrong day.
                'date' => InstanceTime::local($this->inspection->scheduled_date)->translatedFormat('j M Y'),
            ]))
            ->greeting(__('Hello,'))
            ->line(__('A :type has been scheduled for :title (:reference) on :date.', [
                'type' => mb_strtolower($this->inspection->type->label()),
                'title' => $project->title,
                'reference' => $project->reference,
                'date' => InstanceTime::local($this->inspection->scheduled_date)->translatedFormat('j M Y'),
            ]))
            ->line(__('Objectives: :objectives', ['objectives' => $this->inspection->objectives ?? '—']));

        if ($this->inspection->isProposed()) {
            $message->line(__('This visit was proposed automatically because the project has gone past its routine monitoring interval. Confirm or cancel it in the workspace.'));
        }

        return $message
            ->line(__('Open the conduct form on site: it records your position, the checklist and your photographs as you go.'))
            ->action(__('Open the workspace'), SurfaceUrl::base($this->inspection->tenant));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'inspection.scheduled',
            'inspection_ulid' => $this->inspection->ulid,
            'inspection_type' => $this->inspection->type->value,
            'scheduled_date' => $this->inspection->scheduled_date->toDateString(),
            'project_ulid' => $this->inspection->project->ulid,
            'project_reference' => $this->inspection->project->reference,
            'project_title' => $this->inspection->project->title,
            'proposed' => $this->inspection->isProposed(),
            'tenant_id' => $this->inspection->tenant_id,
        ];
    }
}
