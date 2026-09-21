<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

/**
 * Makes a notification preference-aware (rules/architecture.md: "Notifications
 * are queued and preference-aware").
 *
 * Applied in via(), which is the only place that can honour a preference
 * without the sender having to know one exists — a job dispatching to five
 * recipients must not branch per person, and a channel added later (SMS,
 * WhatsApp) arrives behind the same abstraction and is filtered by the same
 * call.
 *
 *   public function via(object $notifiable): array
 *   {
 *       return $this->preferredChannels($notifiable, ['database', 'mail']);
 *   }
 *
 *   public function notificationCategory(): string
 *   {
 *       return NotificationCategories::REPORTING;
 *   }
 *
 * Returning an empty list is a valid outcome and Laravel handles it: the
 * notification is simply not delivered on any channel.
 */
trait RespectsPreferences
{
    /** Which preference switch governs this notification. */
    abstract public function notificationCategory(): string;

    /**
     * @param  list<string>  $channels  the channels this notification would use
     * @return list<string>
     */
    protected function preferredChannels(object $notifiable, array $channels): array
    {
        return NotificationCategories::filter($this->notificationCategory(), $notifiable, $channels);
    }
}
