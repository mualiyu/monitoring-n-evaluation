<?php

namespace App\Notifications\Iam;

use App\Models\Invitation;
use App\Notifications\Concerns\NotificationCategories;
use App\Notifications\Concerns\RespectsPreferences;
use App\Support\SurfaceUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invitation mail. The acceptance URL is built from config (SurfaceUrl), not
 * the request — this runs in a queue worker where no request host exists.
 * The plaintext token lives only inside this notification instance.
 */
class UserInvited extends Notification implements ShouldQueue
{
    use Queueable, RespectsPreferences;

    public function __construct(
        private readonly Invitation $invitation,
        private readonly string $plaintextToken,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->preferredChannels($notifiable, ['mail']);
    }

    public function notificationCategory(): string
    {
        return NotificationCategories::IAM;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $instance = config('platform.instance.name');
        $tenant = $this->invitation->tenant; // null on oversight invitations
        $target = $tenant->name ?? __('the state oversight workspace');
        $url = SurfaceUrl::invitation($tenant, $this->plaintextToken);

        return (new MailMessage)
            ->subject(__('You have been invited to :instance', ['instance' => $instance]))
            ->greeting(__('Hello,'))
            ->line(__('You have been invited to join :target on :instance as :role.', [
                'target' => $target,
                'instance' => $instance,
                'role' => $this->invitation->role->label(),
            ]))
            ->action(__('Accept invitation'), $url)
            ->line(__('This invitation expires on :date and can only be used once.', [
                'date' => $this->invitation->expires_at->timezone(config('platform.instance.timezone'))->format('j M Y, H:i'),
            ]))
            ->line(__('If you were not expecting this invitation, you can ignore this email.'));
    }
}
