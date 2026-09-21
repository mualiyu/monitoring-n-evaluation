<?php

namespace App\Notifications\Inspections;

use App\Models\SiteInspection;
use App\Notifications\Concerns\NotificationCategories;
use App\Notifications\Concerns\RespectsPreferences;
use App\Support\InstanceTime;
use App\Support\SurfaceUrl;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A failing site, announced to the people who can do something about it.
 *
 * This is the manual's exception-reporting reflex (digest §4, "Exception
 * Report — on critical incidence / high deviation") applied to field work: a
 * `major_issues` or `work_stopped` verdict reaches the MDA admin the moment it
 * is filed, rather than surfacing at the next monthly review meeting three
 * weeks later, by which time the contractor has been paid.
 *
 * NOT queued itself: it is sent from inside a queued, tenant-aware job.
 */
class InspectionOutcomeEscalated extends Notification
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
            ->subject(__('Site inspection escalation: :title', ['title' => $project->title]))
            ->greeting(__('Hello,'))
            ->line(__('The :type of :title (:reference) on :date returned a verdict of :outcome.', [
                'type' => mb_strtolower($this->inspection->type->label()),
                'title' => $project->title,
                'reference' => $project->reference,
                'date' => $this->inspection->conducted_at === null
                    ? '—'
                    : InstanceTime::local($this->inspection->conducted_at)->translatedFormat('j M Y'),
                'outcome' => $outcome?->label() ?? '—',
            ]));

        if ($outcome !== null) {
            $message->line($outcome->description());
        }

        if ($this->inspection->findings !== null) {
            $message->line(__('Findings: :findings', [
                'findings' => Str::limit($this->inspection->findings, 400),
            ]));
        }

        if ($this->inspection->recommendations !== null) {
            $message->line(__('Recommended action: :recommendations', [
                'recommendations' => Str::limit($this->inspection->recommendations, 400),
            ]));
        }

        return $message
            ->line(__('Inspected by :inspector.', ['inspector' => $this->inspection->leadInspector->name]))
            ->action(__('Open the workspace'), SurfaceUrl::base($this->inspection->tenant));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'inspection.escalated',
            'inspection_ulid' => $this->inspection->ulid,
            'inspection_type' => $this->inspection->type->value,
            'outcome' => $this->inspection->outcome?->value,
            'conducted_at' => $this->inspection->conducted_at?->toDateTimeString(),
            'project_ulid' => $this->inspection->project->ulid,
            'project_reference' => $this->inspection->project->reference,
            'project_title' => $this->inspection->project->title,
            'tenant_id' => $this->inspection->tenant_id,
        ];
    }
}
