<?php

namespace App\Notifications\Lifecycle;

use App\Models\CommencementNotice;
use App\Support\InstanceTime;
use App\Support\SurfaceUrl;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "You have been instructed to commence." Sent to the contractor's people on
 * the project the moment the notice is served.
 *
 * The message carries the four facts the contractor is held to — the date work
 * must start, the duration, the completion date and the sum — because a
 * notification that only says "a document is waiting" makes the recipient log
 * in to find out whether it is urgent.
 *
 * NOT queued itself: it is sent from inside a queued, tenant-aware job.
 */
class CommencementNoticeIssued extends Notification
{
    public function __construct(private readonly CommencementNotice $notice) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $project = $this->notice->project;

        $message = (new MailMessage)
            ->subject(__('Notice to commence: :title', ['title' => $project->title]))
            ->greeting(__('Hello,'))
            ->line(__('A notice to commence has been issued for :title (:reference).', [
                'title' => $project->title,
                'reference' => $project->reference,
            ]));

        if ($this->notice->commencement_date !== null) {
            $message->line(__('Work is to commence on :date.', [
                // The instance's wall clock, not UTC — the date the contractor
                // was actually given.
                'date' => InstanceTime::local($this->notice->commencement_date)->translatedFormat('j M Y'),
            ]));
        }

        if ($this->notice->expected_completion_date !== null) {
            $message->line(__('Expected completion: :date.', [
                'date' => InstanceTime::local($this->notice->expected_completion_date)->translatedFormat('j M Y'),
            ]));
        }

        return $message
            ->line(__('Please confirm receipt in the workspace. The monitoring clock runs from the date this notice was served.'))
            ->action(__('Open the workspace'), SurfaceUrl::base($this->notice->tenant));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'commencement.issued',
            'notice_ulid' => $this->notice->ulid,
            'notice_reference' => $this->notice->reference,
            'project_ulid' => $this->notice->project->ulid,
            'project_title' => $this->notice->project->title,
            'project_reference' => $this->notice->project->reference,
            'commencement_date' => $this->notice->commencement_date?->toDateString(),
            'expected_completion_date' => $this->notice->expected_completion_date?->toDateString(),
            'issued_at' => $this->notice->issued_at?->toDateTimeString(),
            'tenant_id' => $this->notice->tenant_id,
        ];
    }
}
