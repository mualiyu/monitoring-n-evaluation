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
 * "A Field Trip Report is waiting for your sign-off."
 *
 * This one is not a courtesy. The separation guard in
 * TransitionInspectionStatus means the inspector CANNOT clear their own
 * report — so if nobody is told it is waiting, it waits forever, and the
 * state's assurance step quietly becomes optional.
 *
 * NOT queued itself: it is sent from inside a queued, tenant-aware job.
 */
class InspectionReportSubmitted extends Notification
{
    use RespectsPreferences;

    public function __construct(private readonly SiteInspection $inspection) {}

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
        $outcome = $this->inspection->outcome;

        $message = (new MailMessage)
            ->subject(__('Field Trip Report filed: :title', ['title' => $project->title]))
            ->greeting(__('Hello,'))
            ->line(__(':inspector has filed the report for the :type of :title (:reference), conducted on :date.', [
                'inspector' => $this->inspection->submittedBy->name ?? $this->inspection->leadInspector->name,
                'type' => mb_strtolower($this->inspection->type->label()),
                'title' => $project->title,
                'reference' => $project->reference,
                'date' => $this->inspection->conducted_at === null
                    ? '—'
                    : InstanceTime::local($this->inspection->conducted_at)->translatedFormat('j M Y'),
            ]));

        if ($outcome !== null) {
            $message->line(__('Verdict: :outcome — :description', [
                'outcome' => $outcome->label(),
                'description' => $outcome->description(),
            ]));
        }

        if ($this->inspection->report_late) {
            $message->line(__('This report was filed after its deadline.'));
        }

        return $message
            ->line(__('It needs a second pair of eyes: the inspector cannot sign off their own visit.'))
            ->action(__('Open the workspace'), SurfaceUrl::base($this->inspection->tenant));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'inspection.submitted',
            'inspection_ulid' => $this->inspection->ulid,
            'inspection_type' => $this->inspection->type->value,
            'outcome' => $this->inspection->outcome?->value,
            'conducted_at' => $this->inspection->conducted_at?->toDateTimeString(),
            'report_late' => $this->inspection->report_late,
            'project_ulid' => $this->inspection->project->ulid,
            'project_reference' => $this->inspection->project->reference,
            'project_title' => $this->inspection->project->title,
            'tenant_id' => $this->inspection->tenant_id,
        ];
    }
}
