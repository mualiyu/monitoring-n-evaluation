<?php

namespace App\Notifications\Projects;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Notifications\Concerns\NotificationCategories;
use App\Notifications\Concerns\RespectsPreferences;
use App\Support\SurfaceUrl;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "The bridge project you monitor has been suspended." Database + mail, per
 * the notification rules, each filtered by the recipient's own preferences
 * (RespectsPreferences); SMS/WhatsApp arrive behind the same abstraction.
 *
 * NOT queued itself: it is already sent from inside a queued, tenant-aware job,
 * and queueing it again would hand the mailer a second, tenant-less hop.
 */
class ProjectStatusUpdated extends Notification
{
    use RespectsPreferences;

    public function __construct(
        private readonly Project $project,
        private readonly ?ProjectStatus $from,
        private readonly ProjectStatus $to,
        private readonly ?string $reason = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->preferredChannels($notifiable, ['database', 'mail']);
    }

    public function notificationCategory(): string
    {
        return NotificationCategories::PROJECTS;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__(':reference is now :status', [
                'reference' => $this->project->reference,
                'status' => $this->to->label(),
            ]))
            ->greeting(__('Hello,'))
            ->line(__(':title (:reference) moved from :from to :to.', [
                'title' => $this->project->title,
                'reference' => $this->project->reference,
                'from' => $this->from?->label() ?? __('registration'),
                'to' => $this->to->label(),
            ]));

        if ($this->reason !== null && $this->reason !== '') {
            $message->line(__('Reason given: :reason', ['reason' => $this->reason]));
        }

        // The workspace, not a deep link: /projects/{project} arrives with the
        // Livewire slice, and a 404 in a government inbox is worse than a
        // click too many.
        return $message->action(
            __('Open the workspace'),
            SurfaceUrl::base($this->project->tenant),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'project.status_changed',
            'project_ulid' => $this->project->ulid,
            'project_reference' => $this->project->reference,
            'project_title' => $this->project->title,
            'tenant_id' => $this->project->tenant_id,
            'from_status' => $this->from?->value,
            'to_status' => $this->to->value,
            'reason' => $this->reason,
        ];
    }
}
