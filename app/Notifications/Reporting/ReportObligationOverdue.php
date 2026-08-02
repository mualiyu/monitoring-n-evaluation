<?php

namespace App\Notifications\Reporting;

use App\Models\ReportObligation;
use App\Support\InstanceTime;
use App\Support\SurfaceUrl;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your March return is overdue." Sent once per obligation, ever — the
 * `overdue_notified_at` null check on the row is the guarantee, not this class
 * (progress-reporting.md §3).
 *
 * The message says the return is still wanted: the instance accepts late
 * returns by default, and an MDA that believes the window is shut stops
 * reporting altogether (§9.5).
 *
 * NOT queued itself: it is sent from inside a queued, tenant-aware job.
 */
class ReportObligationOverdue extends Notification
{
    public function __construct(private readonly ReportObligation $obligation) {}

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
        $title = $project === null ? __('your workspace') : $project->title;
        $reference = $project === null ? '—' : $project->reference;

        return (new MailMessage)
            ->subject(__(':period progress report is overdue', ['period' => $period->label]))
            ->greeting(__('Hello,'))
            ->line(__('The :period progress report for :title (:reference) was due on :due and has not been filed.', [
                'period' => $period->label,
                'title' => $title,
                'reference' => $reference,
                // The instance's wall clock, not UTC — see ReportObligationDueSoon.
                'due' => InstanceTime::local($this->obligation->due_at)->translatedFormat('j M Y, H:i'),
            ]))
            ->line(__('Please file it — a late return still counts; a missing one does not.'))
            ->action(__('Open the workspace'), SurfaceUrl::base($this->obligation->tenant));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'report.overdue',
            'obligation_id' => $this->obligation->id,
            'due_at' => $this->obligation->due_at->toDateTimeString(),
            'project_ulid' => $this->obligation->project?->ulid,
            'project_reference' => $this->obligation->project?->reference,
            'period_code' => $this->obligation->reportingPeriod->code,
            'period_label' => $this->obligation->reportingPeriod->label,
            'tenant_id' => $this->obligation->tenant_id,
        ];
    }
}
