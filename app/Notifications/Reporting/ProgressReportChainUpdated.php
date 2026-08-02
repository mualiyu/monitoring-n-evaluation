<?php

namespace App\Notifications\Reporting;

use App\Enums\ProgressReportStatus;
use App\Models\ProgressReport;
use App\Support\SurfaceUrl;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "A return is waiting for your review." / "Your March return was sent back."
 * Database + mail, per the notification rules; SMS/WhatsApp arrive behind the
 * same abstraction and per-user channel preferences replace the hard-coded
 * via() when the preferences table lands.
 *
 * ONE parameterized class for the whole chain rather than three near-identical
 * ones (design §3 names ProgressReportSubmitted/Returned/Approved): the
 * platform already does this for the nine project statuses with
 * ProjectStatusUpdated, and *who* hears about a step is a property of the step,
 * decided in the job — not of the message body.
 *
 * NOT queued itself: it is already sent from inside a queued, tenant-aware job,
 * and queueing it again would hand the mailer a second, tenant-less hop.
 */
class ProgressReportChainUpdated extends Notification
{
    public function __construct(
        private readonly ProgressReport $report,
        private readonly ProgressReportStatus $to,
        private readonly ?string $reason = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $project = $this->report->project;
        $period = $this->report->reportingPeriod;

        $message = (new MailMessage)
            ->subject(__(':period progress report for :reference is now :status', [
                'period' => $period->label,
                'reference' => $project->reference,
                'status' => $this->to->label(),
            ]))
            ->greeting(__('Hello,'))
            ->line(__('The :period progress report for :title (:reference) is now :status.', [
                'period' => $period->label,
                'title' => $project->title,
                'reference' => $project->reference,
                'status' => $this->to->label(),
            ]))
            ->line($this->callToAction());

        if ($this->reason !== null && $this->reason !== '') {
            $message->line(__('Reason given: :reason', ['reason' => $this->reason]));
        }

        // The workspace, not a deep link: /reports/{report} arrives with the
        // Livewire slice, and a 404 in a government inbox is worse than a
        // click too many.
        return $message->action(__('Open the workspace'), SurfaceUrl::base($project->tenant));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'report.chain_updated',
            'report_ulid' => $this->report->ulid,
            'report_status' => $this->to->value,
            'project_ulid' => $this->report->project->ulid,
            'project_reference' => $this->report->project->reference,
            'project_title' => $this->report->project->title,
            'period_code' => $this->report->reportingPeriod->code,
            'period_label' => $this->report->reportingPeriod->label,
            'tenant_id' => $this->report->tenant_id,
            'reason' => $this->reason,
        ];
    }

    private function callToAction(): string
    {
        return match ($this->to) {
            ProgressReportStatus::Submitted => __('It is waiting for your review.'),
            ProgressReportStatus::Reviewed => __('It has been reviewed and is waiting for approval.'),
            ProgressReportStatus::Returned => __('It has been sent back for correction — please revise and resubmit it.'),
            ProgressReportStatus::Approved => __('The approved figures have been applied to the project record.'),
            ProgressReportStatus::Draft => __('It has been reopened.'),
        };
    }
}
