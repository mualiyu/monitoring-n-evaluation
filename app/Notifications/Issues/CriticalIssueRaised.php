<?php

namespace App\Notifications\Issues;

use App\Models\Issue;
use App\Notifications\Concerns\NotificationCategories;
use App\Notifications\Concerns\RespectsPreferences;
use App\Notifications\Issues\Concerns\LinksToIssueScreens;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Somebody on site has recorded something critical. Announced the moment it is
 * recorded rather than when the office next opens the register — `issues.create`
 * is deliberately held by consultants and field monitors so the person who
 * sees the problem records it, and a critical raise that then sat unread for a
 * week would make that openness pointless.
 *
 * NOT queued itself: it is sent from inside a queued, tenant-aware job.
 */
class CriticalIssueRaised extends Notification
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

        return (new MailMessage)
            ->subject(__('Critical challenge raised on :project', ['project' => $project->title]))
            ->greeting(__('Hello,'))
            ->line(__(':raiser has recorded a critical challenge against :project (:reference).', [
                'raiser' => $this->issue->raisedBy?->name ?? __('A member of your workspace'),
                'project' => $project->title,
                'reference' => $project->reference,
            ]))
            ->line($this->issue->title)
            ->line($this->issue->description)
            ->line(__('Category: :category', ['category' => $this->issue->category->label()]))
            ->action(__('Open the issue'), $this->issueUrl($this->issue, $project->tenant));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'issue.critical_raised',
            'issue_ulid' => $this->issue->ulid,
            'title' => $this->issue->title,
            'severity' => $this->issue->severity->value,
            'category' => $this->issue->category->value,
            'raised_by_id' => $this->issue->raised_by_id,
            'project_ulid' => $this->issue->project->ulid,
            'project_reference' => $this->issue->project->reference,
            'tenant_id' => $this->issue->tenant_id,
        ];
    }
}
