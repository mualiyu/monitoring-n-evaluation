<?php

namespace App\Notifications\Lifecycle;

use App\Models\CommencementNotice;
use App\Support\InstanceTime;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "This award has no commencement notice and the window has passed." Sent to
 * the MDA administrator once per notice, ever — the `overdue_notified_at`
 * stamp written under a row lock before dispatch is the guarantee, not this
 * class.
 *
 * The failure it reports is the state's own, not the contractor's: nobody told
 * the firm to start. So the message names the deadline that was missed and
 * points at the screen that fixes it in one action, rather than reading as a
 * complaint about someone else.
 *
 * NOT queued itself: it is sent from inside a queued, tenant-aware job.
 */
class CommencementNoticeOverdue extends Notification
{
    public function __construct(
        private readonly CommencementNotice $notice,
        private readonly int $daysLate,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $project = $this->notice->project;

        return (new MailMessage)
            ->subject(__('Commencement notice overdue: :title', ['title' => $project->title]))
            ->greeting(__('Hello,'))
            ->line(__('No commencement notice has been served on the contractor for :title (:reference).', [
                'title' => $project->title,
                'reference' => $project->reference,
            ]))
            ->line(__('It was due on :due — :days late.', [
                'due' => InstanceTime::local($this->notice->due_at)->translatedFormat('j M Y'),
                'days' => trans_choice('{1} :count day|[2,*] :count days', $this->daysLate, ['count' => $this->daysLate]),
            ]))
            ->line(__('Until the notice is served the contract has no start date on the record, and the inspection clock cannot run.'))
            // route(), not a hand-built path: it fails loudly on a missing
            // route or a wrong binding key, where a string 404s silently — and
            // the {tenant} parameter puts the link on the right subdomain.
            ->action(__('Serve the notice'), route('tenant.projects.commencement', [
                'tenant' => $this->notice->tenant->slug,
                'project' => $project->ulid,
            ]));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'commencement.overdue',
            'notice_ulid' => $this->notice->ulid,
            'project_ulid' => $this->notice->project->ulid,
            'project_title' => $this->notice->project->title,
            'project_reference' => $this->notice->project->reference,
            'due_at' => $this->notice->due_at->toDateString(),
            'days_late' => $this->daysLate,
            'tenant_id' => $this->notice->tenant_id,
        ];
    }
}
