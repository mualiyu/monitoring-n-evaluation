<?php

declare(strict_types=1);

namespace App\Livewire\Shared\Concerns;

use App\Actions\Settings\SaveNotificationPreferences;
use App\Models\User;
use App\Notifications\Concerns\NotificationCategories;
use Livewire\Attributes\Computed;

/**
 * "Stop emailing me about every project status change."
 *
 * The preferences screen, for BOTH app surfaces. The preference itself is
 * global — one person, one inbox — so the only thing a surface adds is its
 * shell and its back link. Oversight-only accounts (state M&E directors, the
 * governor's office) hold no workspace membership and could never reach the
 * workspace copy, which left them with no way to mute anything at all.
 *
 * YOUR OWN ACCOUNT ONLY. There is no user parameter and no administrative
 * path: a preference is not a lever one person pulls on another, and an admin
 * quietly muting a director's overdue-return mail is exactly the silence an
 * audit trail is supposed to make impossible.
 *
 * NON-MUTABLE CATEGORIES ARE SHOWN, NOT HIDDEN. An invitation carries a
 * credential and an expiry; muting it would lock somebody out while telling
 * them nothing. The screen says so in a row the person can read rather than
 * leaving them to wonder why the switch is missing.
 */
trait ManagesNotificationPreferences
{
    /**
     * category => channel => enabled. True (or absent) means delivered.
     *
     * @var array<string, array<string, bool>>
     */
    public array $preferences = [];

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);

        $this->loadPreferences();
    }

    private function loadPreferences(): void
    {
        $user = $this->actor();
        $preferences = [];

        foreach (NotificationCategories::all() as $category => $definition) {
            if ($definition['mutable'] !== true) {
                continue;
            }

            foreach (NotificationCategories::CHANNELS as $channel) {
                $preferences[$category][$channel] = ! $user->hasMutedNotifications($category, $channel);
            }
        }

        $this->preferences = $preferences;
    }

    public function save(): void
    {
        abort_unless(auth()->check(), 403);

        (new SaveNotificationPreferences)($this->actor(), $this->preferences);

        $this->loadPreferences();

        session()->flash('status', __('Your notification preferences are saved.'));
    }

    /** @return array<string, array{label: string, description: string, mutable: bool}> */
    #[Computed]
    public function categories(): array
    {
        return NotificationCategories::all();
    }

    /** @return list<string> */
    public function channels(): array
    {
        return NotificationCategories::CHANNELS;
    }

    public function channelLabel(string $channel): string
    {
        return NotificationCategories::channelLabel($channel);
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
