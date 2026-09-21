<?php

namespace App\Notifications\Evaluation;

use App\Models\Evaluation;
use App\Notifications\Concerns\NotificationCategories;
use App\Notifications\Concerns\RespectsPreferences;
use App\Support\SurfaceUrl;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "You are on the team for this evaluation." Sent to the evaluators who hold
 * platform accounts when they are added to a roster — external evaluators are
 * on the record but have no inbox here.
 *
 * NOT queued itself: it is already sent from inside a queued, tenant-aware job,
 * and queueing it again would hand the mailer a second, tenant-less hop.
 */
class EvaluationCommissioned extends Notification
{
    use RespectsPreferences;

    public function __construct(private readonly Evaluation $evaluation) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->preferredChannels($notifiable, ['database', 'mail']);
    }

    public function notificationCategory(): string
    {
        return NotificationCategories::EVALUATION;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('You have been assigned to the :type of :subject', [
                'type' => mb_strtolower($this->evaluation->type->label()),
                'subject' => $this->evaluation->subjectLabel(),
            ]))
            ->greeting(__('Hello,'))
            ->line(__('You are on the team for “:title”, commissioned by :sponsor.', [
                'title' => $this->evaluation->title,
                'sponsor' => $this->evaluation->sponsor,
            ]))
            ->line($this->evaluation->purpose);

        if ($this->evaluation->report_due_on !== null) {
            $message->line(__('The report is due on :date.', [
                'date' => $this->evaluation->report_due_on->translatedFormat('j F Y'),
            ]));
        }

        // The workspace, not a deep link: a notification is dispatched from a
        // worker with no request host, and a 404 in a government inbox is
        // worse than a click too many.
        return $message->action(
            __('Open the workspace'),
            SurfaceUrl::base($this->evaluation->tenant),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'evaluation.commissioned',
            'evaluation_ulid' => $this->evaluation->ulid,
            'evaluation_title' => $this->evaluation->title,
            'evaluation_type' => $this->evaluation->type->value,
            'subject' => $this->evaluation->subjectLabel(),
            'report_due_on' => $this->evaluation->report_due_on?->toDateString(),
            'tenant_id' => $this->evaluation->tenant_id,
        ];
    }
}
