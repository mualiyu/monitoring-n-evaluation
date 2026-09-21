<?php

namespace App\Notifications\Inspections;

use App\Models\SiteInspection;
use App\Notifications\Concerns\NotificationCategories;
use App\Notifications\Concerns\RespectsPreferences;
use App\Support\InstanceTime;
use App\Support\SurfaceUrl;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "You inspected this site four days ago and the report has not arrived."
 *
 * Sent EXACTLY ONCE per inspection, ever — the guarantee lives in the
 * `report_overdue_notified_at` gate advanced under a row lock in
 * FlagOverdueInspectionReports, not here. A daily nag teaches inspectors to
 * filter this sender; the standing signal belongs on the board, where the M&E
 * officer sees every late report at once.
 *
 * NOT queued itself: it is sent from inside a queued, tenant-aware job.
 */
class InspectionReportOverdue extends Notification
{
    use RespectsPreferences;

    public function __construct(
        private readonly SiteInspection $inspection,
        private readonly int $daysLate,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->preferredChannels($notifiable, ['database', 'mail']);
    }

    public function notificationCategory(): string
    {
        return NotificationCategories::INSPECTIONS;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $project = $this->inspection->project;

        return (new MailMessage)
            ->subject(__('Field Trip Report outstanding: :title', ['title' => $project->title]))
            ->greeting(__('Hello,'))
            ->line(__('The :type of :title (:reference) was conducted on :date and its report has not been filed.', [
                'type' => mb_strtolower($this->inspection->type->label()),
                'title' => $project->title,
                'reference' => $project->reference,
                'date' => $this->inspection->conducted_at === null
                    ? '—'
                    : InstanceTime::local($this->inspection->conducted_at)->translatedFormat('j M Y'),
            ]))
            ->line(trans_choice(
                '{1} It is :count day past its deadline.|[2,*] It is :count days past its deadline.',
                max($this->daysLate, 1),
                ['count' => max($this->daysLate, 1)],
            ))
            ->line(__('A finding that stays in a notebook is a finding nobody can act on. Your draft is saved — reopen the conduct form and file it.'))
            ->action(__('Open the workspace'), SurfaceUrl::base($this->inspection->tenant));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'inspection.report_overdue',
            'inspection_ulid' => $this->inspection->ulid,
            'inspection_type' => $this->inspection->type->value,
            'days_late' => $this->daysLate,
            'report_due_at' => $this->inspection->report_due_at?->toDateTimeString(),
            'project_ulid' => $this->inspection->project->ulid,
            'project_reference' => $this->inspection->project->reference,
            'project_title' => $this->inspection->project->title,
            'tenant_id' => $this->inspection->tenant_id,
        ];
    }
}
