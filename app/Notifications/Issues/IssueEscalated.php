<?php

namespace App\Notifications\Issues;

use App\Models\Issue;
use App\Notifications\Concerns\NotificationCategories;
use App\Notifications\Concerns\RespectsPreferences;
use App\Notifications\Issues\Concerns\LinksToIssueScreens;
use App\Support\InstanceTime;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The sanctions ladder in message form: an obstruction has sat open past its
 * severity's allowance and is now a director's problem.
 *
 * The message names the ALLOWANCE as well as the elapsed time, because the
 * first question a director asks of an escalation is "why am I being told
 * this now" — and an escalation that cannot answer it gets muted.
 *
 * NOT queued itself: it is sent from inside a queued, tenant-aware job.
 */
class IssueEscalated extends Notification
{
    use LinksToIssueScreens, RespectsPreferences;

    public function __construct(private readonly Issue $issue) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->preferredChannels($notifiable, ['database', 'mail']);
    }

    public function notificationCategory(): string
    {
        return NotificationCategories::ISSUES;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $project = $this->issue->project;
        $daysOpen = $this->daysOpen();

        return (new MailMessage)
            ->subject(__('Escalation: :title has been open :days day(s)', [
                'title' => $this->issue->title,
                'days' => $daysOpen,
            ]))
            ->greeting(__('Hello,'))
            ->line(__('A :severity challenge on :project (:reference) has been open for :days day(s) without being cleared.', [
                'severity' => $this->issue->severity->label(),
                'project' => $project->title,
                'reference' => $project->reference,
                'days' => $daysOpen,
            ]))
            ->line($this->issue->title)
            ->line(__('It has been escalated to you because it passed the allowance this entity sets for :severity issues.', [
                'severity' => $this->issue->severity->label(),
            ]))
            ->line($this->issue->owner === null
                ? __('Nobody owns it yet. Assigning an owner is the first thing that will move it.')
                : __('Its owner is :owner.', ['owner' => $this->issue->owner->name]))
            ->action(__('Open the issue'), $this->issueUrl($this->issue, $project->tenant));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'issue.escalated',
            'issue_ulid' => $this->issue->ulid,
            'title' => $this->issue->title,
            'severity' => $this->issue->severity->value,
            'days_open' => $this->daysOpen(),
            'owner_id' => $this->issue->owner_id,
            'project_ulid' => $this->issue->project->ulid,
            'project_reference' => $this->issue->project->reference,
            'tenant_id' => $this->issue->tenant_id,
        ];
    }

    /**
     * Counted on the INSTANCE's calendar rather than UTC's, for the same
     * reason ReportObligation::daysToDue() is: an officer in Lagos at 00:30 is
     * on the next day, and a count that disagrees with the screen destroys
     * trust in both.
     */
    private function daysOpen(): int
    {
        return (int) InstanceTime::local($this->issue->created_at)
            ->startOfDay()
            ->diffInDays(InstanceTime::now()->startOfDay(), false);
    }
}
