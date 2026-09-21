<?php

declare(strict_types=1);

namespace App\Livewire\Shared\Concerns;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Concerns\NotificationFeed;
use App\Notifications\Concerns\NotificationLink;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * The notification centre's behaviour, shared by the workspace and state
 * screens.
 *
 * A trait rather than two classes because the two surfaces differ in exactly
 * two things — which shell they render in and which feed they read — and
 * everything else (filtering, marking read, the link through to the record) is
 * one behaviour that must not drift. Two copies of "mark all as read" is two
 * chances for one of them to forget the workspace filter.
 */
trait ManagesNotificationFeed
{
    /** unread | all */
    #[Url(except: 'unread')]
    public string $filter = 'unread';

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, DatabaseNotification> */
    #[Computed]
    public function notifications(): LengthAwarePaginator
    {
        return NotificationFeed::for($this->actor(), $this->feedTenant(), $this->filter === 'unread')
            ->paginate(25);
    }

    #[Computed]
    public function unreadCount(): int
    {
        return NotificationFeed::unreadCount($this->actor(), $this->feedTenant());
    }

    public function markRead(string $id): void
    {
        $notification = NotificationFeed::for($this->actor(), $this->feedTenant())
            ->whereKey($id)
            ->first();

        // The feed is already the signed-in person's own, so another account's
        // notification id resolves to nothing rather than to a 403 that would
        // confirm it exists.
        abort_if($notification === null, 404);

        $notification->markAsRead();

        unset($this->notifications, $this->unreadCount);
    }

    public function markUnread(string $id): void
    {
        $notification = NotificationFeed::for($this->actor(), $this->feedTenant())
            ->whereKey($id)
            ->first();

        abort_if($notification === null, 404);

        $notification->forceFill(['read_at' => null])->save();

        unset($this->notifications, $this->unreadCount);
    }

    public function markAllRead(): void
    {
        // reorder(): an UPDATE carries no ORDER BY, and leaving the feed's
        // sort on the builder would put one there on engines that accept it.
        NotificationFeed::for($this->actor(), $this->feedTenant(), true)
            ->reorder()
            ->update(['read_at' => now()]);

        unset($this->notifications, $this->unreadCount);
    }

    public function linkFor(DatabaseNotification $notification): ?string
    {
        return NotificationLink::for($notification, $this->feedTenant() instanceof Tenant);
    }

    public function summaryFor(DatabaseNotification $notification): string
    {
        return NotificationLink::summary($notification);
    }

    public function headlineFor(DatabaseNotification $notification): string
    {
        return NotificationLink::headline($notification);
    }

    /** @return array<string, string> */
    public function filterOptions(): array
    {
        return ['unread' => __('Unread only'), 'all' => __('Everything')];
    }

    /** Which feed this surface reads — null means "everything this person has". */
    abstract protected function feedTenant(): ?Tenant;

    protected function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
