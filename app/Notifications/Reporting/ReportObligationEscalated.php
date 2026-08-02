<?php

namespace App\Notifications\Reporting;

use App\Models\ReportObligation;
use App\Support\InstanceTime;
use App\Support\SurfaceUrl;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The sanctions ladder in message form (progress-reporting.md §3): stage 1
 * tells the MDA admin that a project under them is silent, stage 2 tells state
 * oversight that the MDA is.
 *
 * The point of escalation is that a director — and then the secretariat —
 * learns of a non-reporting project without anyone having to run a report.
 *
 * NOT queued itself: it is sent from inside a queued, tenant-aware job.
 */
class ReportObligationEscalated extends Notification
{
    public function __construct(
        private readonly ReportObligation $obligation,
        private readonly int $stage,
        private readonly int $daysLate,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $project = $this->obligation->project;
        $period = $this->obligation->reportingPeriod;

        // An obligation with no project is an MDA-level one (Phase 2
        // consolidation); the message has to stand without a project name.
        $title = $project === null ? __('An MDA-level obligation') : $project->title;
        $reference = $project === null ? '—' : $project->reference;

        return (new MailMessage)
            ->subject(__('Escalation: :period progress report is :days day(s) overdue', [
                'period' => $period->label,
                'days' => $this->daysLate,
            ]))
            ->greeting(__('Hello,'))
            ->line(__(':title (:reference) has not filed its :period progress report, due on :due.', [
                'title' => $title,
                'reference' => $reference,
                'period' => $period->label,
                // The instance's wall clock, not UTC — see ReportObligationDueSoon.
                'due' => InstanceTime::local($this->obligation->due_at)->translatedFormat('j M Y, H:i'),
            ]))
            ->line(__('This obligation has been escalated to you because it remains unmet :days day(s) after the deadline.', [
                'days' => $this->daysLate,
            ]))
            ->action(__('Open the workspace'), SurfaceUrl::base($this->obligation->tenant));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'report.escalated',
            'obligation_id' => $this->obligation->id,
            'escalation_stage' => $this->stage,
            'days_late' => $this->daysLate,
            'due_at' => $this->obligation->due_at->toDateTimeString(),
            'project_ulid' => $this->obligation->project?->ulid,
            'project_reference' => $this->obligation->project?->reference,
            'period_code' => $this->obligation->reportingPeriod->code,
            'period_label' => $this->obligation->reportingPeriod->label,
            'tenant_id' => $this->obligation->tenant_id,
        ];
    }
}
