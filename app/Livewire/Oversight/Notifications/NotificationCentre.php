<?php

declare(strict_types=1);

namespace App\Livewire\Oversight\Notifications;

use App\Livewire\Shared\Concerns\ManagesNotificationFeed;
use App\Models\Tenant;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The state inbox: everything this person has been told, from every workspace
 * they have authority over plus the state-level notifications addressed to
 * them.
 *
 * Unfiltered by workspace on purpose — the oversight surface binds no tenant,
 * and a secretariat officer following a late return in one ministry and an
 * escalation in another is reading one desk, not two.
 */
#[Layout('layouts::oversight')]
class NotificationCentre extends Component
{
    use ManagesNotificationFeed, WithPagination;

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);
    }

    protected function feedTenant(): ?Tenant
    {
        return null;
    }

    public function surfaceDescription(): string
    {
        return __('Everything you have been told across every entity, newest first.');
    }

    public function render(): View
    {
        return view('livewire.shared.notification-centre');
    }
}
