<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Models\User;
use App\Notifications\Concerns\NotificationCategories;

/**
 * Writes a person's notification mutes.
 *
 * Only ever your own account: a preference is not an administrative lever, and
 * an admin quietly muting a director's overdue-report mail is precisely the
 * silence an audit is supposed to make impossible. The column is therefore not
 * fillable and this is its only writer.
 *
 * Stored SPARSELY — only the switches that are off. Everything absent is on,
 * so a category added by a later module is delivered to everyone by default
 * instead of inheriting a stale false from a preference row written before it
 * existed.
 */
class SaveNotificationPreferences
{
    /**
     * @param  array<string, array<string, bool>>  $preferences  category => channel => enabled
     */
    public function __invoke(User $user, array $preferences): void
    {
        $mutes = [];

        foreach (NotificationCategories::all() as $category => $definition) {
            if ($definition['mutable'] !== true) {
                continue; // Credential-bearing categories are always delivered.
            }

            foreach (NotificationCategories::CHANNELS as $channel) {
                $enabled = $preferences[$category][$channel] ?? true;

                if ($enabled === false) {
                    $mutes[$category][$channel] = false;
                }
            }
        }

        $previous = $user->notification_preferences ?? [];

        if ($previous === $mutes) {
            return; // Idempotent: no phantom audit rows for a no-op.
        }

        $user->forceFill(['notification_preferences' => $mutes === [] ? null : $mutes])->save();

        activity('settings')
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties([
                'old' => ['notification_preferences' => $previous],
                'attributes' => ['notification_preferences' => $mutes],
            ])
            ->log('user.notification_preferences_updated');
    }
}
