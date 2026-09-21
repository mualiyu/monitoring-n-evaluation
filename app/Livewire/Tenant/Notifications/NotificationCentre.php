<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Notifications;

use App\Livewire\Shared\Concerns\ManagesNotificationFeed;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The workspace inbox: everything this person has been told inside THIS
 * workspace, plus the things that belong to them rather than to any ministry.
 *
 * No permission gates it beyond being a member of the workspace (the route
 * group's `tenant.member`): a notification was already addressed to this
 * person by whatever raised it, so a second authority check here could only
 * hide something they were deliberately sent.
 */
#[Layout('layouts::tenant')]
class NotificationCentre extends Component
{
    use ManagesNotificationFeed, WithPagination;

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);
    }

    protected function feedTenant(): ?Tenant
    {
        return app(CurrentTenant::class)->get();
    }

    public function surfaceDescription(): string
    {
        $tenant = $this->feedTenant();

        return $tenant === null
            ? __('Everything you have been told on this platform.')
            : __('Everything you have been told inside :workspace, newest first.', ['workspace' => $tenant->displayName()]);
    }

    public function render(): View
    {
        // Both surfaces render the same screen deliberately — see
        // App\Livewire\Shared\Concerns\ManagesNotificationFeed.
        return view('livewire.shared.notification-centre');
    }
}
