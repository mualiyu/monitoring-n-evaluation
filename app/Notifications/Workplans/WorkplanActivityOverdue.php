<?php

namespace App\Notifications\Workplans;

use App\Models\WorkplanActivity;
use App\Notifications\Concerns\NotificationCategories;
use App\Notifications\Concerns\RespectsPreferences;
use App\Support\SurfaceUrl;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "This activity has passed its planned date and is not finished." Sent once
 * per activity, ever — the gate is `overdue_notified_at`, advanced under a row
 * lock by App\Actions\Workplans\FlagOverdueActivities.
 *
 * Goes to the owner AND the MDA administrator together rather than on a
 * ladder: unlike a statutory report deadline, a slipped activity has no
 * escalation clock in the manual, and the director's value here is that they
 * see the slippage at the same time as the officer does.
 */
class WorkplanActivityOverdue extends Notification
{
    use RespectsPreferences;

    public function __construct(
        private readonly WorkplanActivity $activity,
        private readonly int $daysLate,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->preferredChannels($notifiable, ['database', 'mail']);
    }

    public function notificationCategory(): string
    {
        return NotificationCategories::WORKPLANS;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $workplan = $this->activity->workplan;

        return (new MailMessage)
            ->subject(__('Work-plan activity past its date: :title', ['title' => $this->activity->title]))
            ->greeting(__('Hello,'))
            ->line(__('":activity" in the :year work plan ":plan" was due on :due and is :percent% complete.', [
                'activity' => $this->activity->title,
                'year' => $workplan->yearLabel(),
                'plan' => $workplan->title,
                'due' => $this->activity->planned_end->translatedFormat('j M Y'),
                'percent' => $this->activity->progress_percent,
            ]))
            ->line(trans_choice(
                '{1}It is one day past its planned date.|[2,*]It is :count days past its planned date.',
                $this->daysLate,
                ['count' => $this->daysLate],
            ))
            ->line(__('Record the progress achieved, or revise the plan if the schedule no longer holds.'))
            ->action(__('Open the workspace'), SurfaceUrl::base($workplan->tenant));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'workplan.activity_overdue',
            'activity_ulid' => $this->activity->ulid,
            'activity_title' => $this->activity->title,
            'workplan_ulid' => $this->activity->workplan->ulid,
            'planned_end' => $this->activity->planned_end->toDateString(),
            'days_late' => $this->daysLate,
            'progress_percent' => $this->activity->progress_percent,
            'tenant_id' => $this->activity->tenant_id,
        ];
    }
}
