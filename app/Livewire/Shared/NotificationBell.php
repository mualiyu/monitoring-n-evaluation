<?php

declare(strict_types=1);

namespace App\Livewire\Shared;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Concerns\NotificationFeed;
use App\Notifications\Concerns\NotificationLink;
use App\Tenancy\CurrentTenant;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The topbar bell, for both app shells:
 *
 *   <livewire:shared.notification-bell />
 *
 * ONE COMPONENT, BOTH SURFACES. What differs between them is only which feed
 * the person is looking at, and that follows from whether a tenant is bound —
 * so the shell does not have to pass anything and the two topbars cannot drift
 * apart. On a workspace subdomain the bell shows that workspace's items plus
 * the ones that belong to the person rather than to any ministry; on the state
 * surface it shows everything they have.
 *
 * THE COUNT IS A COUNT, not a page. `wire:poll.visible` keeps it current
 * without the dropdown's rows being fetched on every tick, and the rows are a
 * computed property so they are only queried when the panel is actually open.
 */
class NotificationBell extends Component
{
    /** How many rows the dropdown previews before "see everything". */
    public int $preview = 5;

    #[Computed]
    public function unreadCount(): int
    {
        return NotificationFeed::unreadCount($this->actor(), $this->tenant());
    }

    /** @return Collection<int, DatabaseNotification> */
    #[Computed]
    public function recent(): Collection
    {
        /** @var Collection<int, DatabaseNotification> $rows */
        $rows = NotificationFeed::for($this->actor(), $this->tenant())
            ->limit($this->preview)
            ->get();

        return $rows;
    }

    public function markRead(string $id): void
    {
        $notification = NotificationFeed::for($this->actor(), $this->tenant())
            ->whereKey($id)
            ->first();

        // Scoped to the signed-in person's own feed, so another account's
        // notification id simply resolves to nothing.
        abort_if($notification === null, 404);

        $notification->markAsRead();

        unset($this->unreadCount, $this->recent);
    }

    public function markAllRead(): void
    {
        NotificationFeed::for($this->actor(), $this->tenant(), true)
            ->update(['read_at' => now()]);

        unset($this->unreadCount, $this->recent);
    }

    public function linkFor(DatabaseNotification $notification): ?string
    {
        return NotificationLink::for($notification, $this->tenant() instanceof Tenant);
    }

    public function summaryFor(DatabaseNotification $notification): string
    {
        return NotificationLink::summary($notification);
    }

    public function headlineFor(DatabaseNotification $notification): string
    {
        return NotificationLink::headline($notification);
    }

    /**
     * The notification centre for whichever surface this is rendering on.
     * Route::has guards the case where a shell embeds the bell before the
     * centre's route file is loaded.
     */
    public function centreUrl(): ?string
    {
        $name = $this->tenant() instanceof Tenant ? 'tenant.notifications.index' : 'oversight.notifications.index';

        return Route::has($name) ? route($name) : null;
    }

    private function tenant(): ?Tenant
    {
        return app(CurrentTenant::class)->get();
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function render(): View
    {
        return view('livewire.shared.notification-bell');
    }
}
