<?php

namespace App\Notifications\Workplans;

use App\Models\WorkplanActivity;
use App\Support\SurfaceUrl;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "You are responsible for this activity." Sent to the named owner when a work
 * -plan line is created against them or handed over — the moment accountability
 * moves, which is the only moment it is worth an email.
 */
class WorkplanActivityAssigned extends Notification
{
    public function __construct(private readonly WorkplanActivity $activity) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $workplan = $this->activity->workplan;

        return (new MailMessage)
            ->subject(__('You have been assigned a work-plan activity: :title', [
                'title' => $this->activity->title,
            ]))
            ->greeting(__('Hello,'))
            ->line(__('You are now responsible for ":activity" in the :year annual work plan ":plan".', [
                'activity' => $this->activity->title,
                'year' => $workplan->yearLabel(),
                'plan' => $workplan->title,
            ]))
            ->line(__('It is scheduled from :start to :end.', [
                'start' => $this->activity->planned_start->translatedFormat('j M Y'),
                'end' => $this->activity->planned_end->translatedFormat('j M Y'),
            ]))
            ->action(__('Open the workspace'), SurfaceUrl::base($workplan->tenant));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'workplan.activity_assigned',
            'activity_ulid' => $this->activity->ulid,
            'activity_title' => $this->activity->title,
            'workplan_ulid' => $this->activity->workplan->ulid,
            'workplan_title' => $this->activity->workplan->title,
            'planned_end' => $this->activity->planned_end->toDateString(),
            'tenant_id' => $this->activity->tenant_id,
        ];
    }
}
