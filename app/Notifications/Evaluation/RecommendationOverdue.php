<?php

namespace App\Notifications\Evaluation;

use App\Models\Recommendation;
use App\Notifications\Concerns\NotificationCategories;
use App\Notifications\Concerns\RespectsPreferences;
use App\Support\SurfaceUrl;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "A recommendation addressed to you is past its date."
 *
 * Sent once per recommendation, ever — the idempotency gate lives on the row
 * (`overdue_flagged_at`), advanced under the same lock as the dispatch. It
 * goes to the addressee AND to the MDA admin, because the point of the
 * escalation is that someone accountable for the register learns of a silent
 * addressee without having to run a report.
 */
class RecommendationOverdue extends Notification
{
    use RespectsPreferences;

    public function __construct(
        private readonly Recommendation $recommendation,
        private readonly int $daysLate,
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
        return (new MailMessage)
            ->subject(__('Overdue recommendation: :title', ['title' => $this->recommendation->title]))
            ->greeting(__('Hello,'))
            ->line(trans_choice(
                '{1} A recommendation is :count day past its due date and has not been implemented.'
                .'|[2,*] A recommendation is :count days past its due date and has not been implemented.',
                $this->daysLate,
                ['count' => $this->daysLate],
            ))
            ->line(__('“:title” — addressed to :addressee, currently :status.', [
                'title' => $this->recommendation->title,
                'addressee' => $this->recommendation->addresseeLabel(),
                'status' => mb_strtolower($this->recommendation->status->label()),
            ]))
            ->line(__('An outstanding recommendation is what an evaluation is judged by. Record what has happened, or close it with a reason.'))
            ->action(
                __('Open the follow-up register'),
                SurfaceUrl::base($this->recommendation->tenant),
            );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'recommendation.overdue',
            'recommendation_ulid' => $this->recommendation->ulid,
            'recommendation_title' => $this->recommendation->title,
            'priority' => $this->recommendation->priority->value,
            'status' => $this->recommendation->status->value,
            'due_on' => $this->recommendation->due_on?->toDateString(),
            'days_late' => $this->daysLate,
            'tenant_id' => $this->recommendation->tenant_id,
        ];
    }
}
