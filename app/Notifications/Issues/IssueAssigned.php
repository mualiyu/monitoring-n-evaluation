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
 * "This is yours to clear." The single most load-bearing message the register
 * sends, because an issue with no name against it is an issue nobody is
 * accountable for.
 *
 * NOT queued itself: it is sent from inside a queued, tenant-aware job.
 */
class IssueAssigned extends Notification
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

        $message = (new MailMessage)
            ->subject(__('Assigned to you: :title', ['title' => $this->issue->title]))
            ->greeting(__('Hello,'))
            ->line(__('You have been made the owner of a challenge raised against :project (:reference).', [
                'project' => $project->title,
                'reference' => $project->reference,
            ]))
            ->line(__(':severity — :category', [
                'severity' => $this->issue->severity->label(),
                'category' => $this->issue->category->label(),
            ]))
            ->line($this->issue->description);

        if ($this->issue->due_date !== null) {
            $message->line(__('Corrective action is due by :date.', [
                // The state's wall clock, not UTC — the deadline the owner was
                // actually given.
                'date' => InstanceTime::local($this->issue->due_date)->translatedFormat('j M Y'),
            ]));
        }

        return $message->action(__('Open the issue'), $this->issueUrl($this->issue, $project->tenant));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'issue.assigned',
            'issue_ulid' => $this->issue->ulid,
            'title' => $this->issue->title,
            'severity' => $this->issue->severity->value,
            'category' => $this->issue->category->value,
            'due_date' => $this->issue->due_date?->toDateString(),
            'project_ulid' => $this->issue->project->ulid,
            'project_reference' => $this->issue->project->reference,
            'tenant_id' => $this->issue->tenant_id,
        ];
    }
}
