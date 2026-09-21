<?php

namespace App\Notifications\Evaluation;

use App\Models\Recommendation;
use App\Notifications\Concerns\NotificationCategories;
use App\Notifications\Concerns\RespectsPreferences;
use App\Support\SurfaceUrl;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "An evaluation has addressed a recommendation to you."
 *
 * This is the first half of the knowledge-management loop: a recommendation
 * nobody was told about is one nobody will implement. The deadline is in the
 * message body on purpose — the second half of the loop is the overdue sweep,
 * and it should never be the first time someone hears there was a date.
 */
class RecommendationAssigned extends Notification
{
    use RespectsPreferences;

    public function __construct(private readonly Recommendation $recommendation) {}

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
            ->subject(__('Recommendation for your action: :title', [
                'title' => $this->recommendation->title,
            ]))
            ->greeting(__('Hello,'))
            ->line(__('A :priority-priority recommendation from a :source has been addressed to you.', [
                'priority' => mb_strtolower($this->recommendation->priority->label()),
                'source' => mb_strtolower($this->recommendation->sourceLabel()),
            ]))
            ->line($this->recommendation->body);

        if ($this->recommendation->due_on !== null) {
            $message->line(__('It is due by :date.', [
                'date' => $this->recommendation->due_on->translatedFormat('j F Y'),
            ]));
        }

        if ($this->recommendation->estimated_cost !== null) {
            $message->line(__('Estimated cost: :cost.', [
                'cost' => $this->recommendation->estimated_cost->format(),
            ]));
        }

        return $message->action(
            __('Open the follow-up register'),
            SurfaceUrl::base($this->recommendation->tenant),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'recommendation.assigned',
            'recommendation_ulid' => $this->recommendation->ulid,
            'recommendation_title' => $this->recommendation->title,
            'priority' => $this->recommendation->priority->value,
            'due_on' => $this->recommendation->due_on?->toDateString(),
            'source' => $this->recommendation->sourceLabel(),
            'tenant_id' => $this->recommendation->tenant_id,
        ];
    }
}
