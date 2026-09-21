<?php

namespace App\Notifications\Evaluation;

use App\Enums\EvaluationStatus;
use App\Models\Evaluation;
use App\Notifications\Concerns\NotificationCategories;
use App\Notifications\Concerns\RespectsPreferences;
use App\Support\SurfaceUrl;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "A report is waiting for your review." / "The evaluation has been approved."
 *
 * ONE PARAMETERIZED CLASS for the whole chain rather than four near-identical
 * ones: the platform already does this for the nine project statuses and for
 * the progress-report chain, and *who* hears about a step is a property of the
 * step — decided in the job — not of the message body.
 *
 * NOT queued itself: it is already sent from inside a queued, tenant-aware job.
 */
class EvaluationChainUpdated extends Notification
{
    use RespectsPreferences;

    public function __construct(
        private readonly Evaluation $evaluation,
        private readonly EvaluationStatus $to,
        private readonly ?string $reason = null,
    ) {}

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
            ->subject(__('“:title” is now :status', [
                'title' => $this->evaluation->title,
                'status' => $this->to->label(),
            ]))
            ->greeting(__('Hello,'))
            ->line(__('The :type of :subject is now :status.', [
                'type' => mb_strtolower($this->evaluation->type->label()),
                'subject' => $this->evaluation->subjectLabel(),
                'status' => $this->to->label(),
            ]))
            ->line($this->callToAction());

        if ($this->reason !== null && $this->reason !== '') {
            $message->line(__('Reason given: :reason', ['reason' => $this->reason]));
        }

        return $message->action(
            __('Open the workspace'),
            SurfaceUrl::base($this->evaluation->tenant),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'evaluation.chain_updated',
            'evaluation_ulid' => $this->evaluation->ulid,
            'evaluation_title' => $this->evaluation->title,
            'evaluation_status' => $this->to->value,
            'subject' => $this->evaluation->subjectLabel(),
            'tenant_id' => $this->evaluation->tenant_id,
            'reason' => $this->reason,
        ];
    }

    private function callToAction(): string
    {
        return match ($this->to) {
            EvaluationStatus::Planned => __('The commission has been recorded.'),
            EvaluationStatus::InProgress => __('Fieldwork has begun.'),
            EvaluationStatus::DraftReport => __('The report has been sent back for revision — please revise and refile it.'),
            EvaluationStatus::UnderReview => __('The report is waiting for your approval. You cannot approve findings you led or filed.'),
            EvaluationStatus::Approved => __('The findings are settled. The recommendations raised from them are now on the follow-up register.'),
            EvaluationStatus::Published => __('The report has been published and may be disseminated.'),
            EvaluationStatus::Cancelled => __('The commission has been cancelled.'),
        };
    }
}
