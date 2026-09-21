<?php

namespace App\Notifications\Issues;

use App\Enums\ExceptionTrigger;
use App\Models\ExceptionReport;
use App\Notifications\Concerns\NotificationCategories;
use App\Notifications\Concerns\RespectsPreferences;
use App\Notifications\Issues\Concerns\LinksToIssueScreens;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A deviation has been recorded against a project (manual Table 5.2 — the
 * Exception Report).
 *
 * The message QUOTES THE MEASUREMENT, never just the verdict. "Schedule
 * slippage on the township road" invites an argument; "physical progress 22%
 * against 71% of the contract period elapsed, a 49-point gap on a 15-point
 * tolerance" invites an explanation — and an explanation is the whole point of
 * an exception report.
 *
 * NOT queued itself: it is sent from inside a queued, tenant-aware job.
 */
class ExceptionReportRaised extends Notification
{
    use LinksToIssueScreens, RespectsPreferences;

    public function __construct(private readonly ExceptionReport $report) {}

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
        $project = $this->report->project;

        $message = (new MailMessage)
            ->subject(__(':trigger reported on :project', [
                'trigger' => $this->report->trigger->label(),
                'project' => $project->title,
            ]))
            ->greeting(__('Hello,'))
            ->line(__('An exception report has been raised against :project (:reference).', [
                'project' => $project->title,
                'reference' => $project->reference,
            ]))
            ->line($this->report->narrative);

        if ($this->report->measured_value !== null && $this->report->threshold_value !== null) {
            $message->line(__('Measured :measured :unit against a tolerance of :threshold.', [
                'measured' => $this->report->measured_value,
                'unit' => $this->report->trigger->unit(),
                'threshold' => $this->report->threshold_value,
            ]));
        }

        if ($this->report->trigger === ExceptionTrigger::CriticalIncident) {
            $message->line(__('State oversight has been copied on this report.'));
        }

        return $message->action(__('Open the exception report'), $this->exceptionUrl($this->report, $project->tenant));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'exception.raised',
            'exception_ulid' => $this->report->ulid,
            'trigger' => $this->report->trigger->value,
            'severity' => $this->report->severity->value,
            'measured_value' => $this->report->measured_value,
            'threshold_value' => $this->report->threshold_value,
            'automatic' => $this->report->isAutomatic(),
            'project_ulid' => $this->report->project->ulid,
            'project_reference' => $this->report->project->reference,
            'tenant_id' => $this->report->tenant_id,
        ];
    }
}
