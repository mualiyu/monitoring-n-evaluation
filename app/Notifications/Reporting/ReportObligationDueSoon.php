<?php

namespace App\Notifications\Reporting;

use App\Models\ReportObligation;
use App\Notifications\Concerns\NotificationCategories;
use App\Notifications\Concerns\RespectsPreferences;
use App\Support\InstanceTime;
use App\Support\SurfaceUrl;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your March return is due in 3 days." One rung of the reminder ladder
 * (progress-reporting.md §3), sent exactly once per rung — the guarantee lives
 * in the obligation's monotonic counter, not here.
 *
 * NOT queued itself: it is sent from inside a queued, tenant-aware job.
 */
class ReportObligationDueSoon extends Notification
{
    use RespectsPreferences;

    public function __construct(
        private readonly ReportObligation $obligation,
        private readonly int $daysBefore,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->preferredChannels($notifiable, ['database', 'mail']);
    }

    public function notificationCategory(): string
    {
        return NotificationCategories::REPORTING;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $project = $this->obligation->project;
        $period = $this->obligation->reportingPeriod;

        // An obligation with no project is an MDA-level one (Phase 2
        // consolidation); the message has to stand without a project name.
        $title = $project === null ? __('your workspace') : $project->title;
        $reference = $project === null ? '—' : $project->reference;

        return (new MailMessage)
            ->subject(__(':period progress report due in :days day(s)', [
                'period' => $period->label,
                'days' => $this->daysBefore,
            ]))
            ->greeting(__('Hello,'))
            ->line(__('The :period progress report for :title (:reference) is due on :due.', [
                'period' => $period->label,
                'title' => $title,
                'reference' => $reference,
                // Rendered on the instance's wall clock: the stored instant is
                // UTC, and an MDA told its deadline is "8 April 00:59" for a
                // return due at the end of the 7th stops trusting the mail.
                'due' => InstanceTime::local($this->obligation->due_at)->translatedFormat('j M Y, H:i'),
            ]))
            ->line(__('Returns filed after the deadline are recorded as late on the state compliance report.'))
            ->action(__('Open the workspace'), SurfaceUrl::base($this->obligation->tenant));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'report.due_soon',
            'obligation_id' => $this->obligation->id,
            'days_before' => $this->daysBefore,
            'due_at' => $this->obligation->due_at->toDateTimeString(),
            'project_ulid' => $this->obligation->project?->ulid,
            'project_reference' => $this->obligation->project?->reference,
            'period_code' => $this->obligation->reportingPeriod->code,
            'period_label' => $this->obligation->reportingPeriod->label,
            'tenant_id' => $this->obligation->tenant_id,
        ];
    }
}
