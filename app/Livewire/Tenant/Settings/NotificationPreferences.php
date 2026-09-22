<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Settings;

use App\Livewire\Shared\Concerns\ManagesNotificationPreferences;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Personal notification preferences, in the workspace shell. The screen itself
 * lives in ManagesNotificationPreferences, shared with the oversight surface.
 */
#[Layout('layouts::tenant')]
class NotificationPreferences extends Component
{
    use ManagesNotificationPreferences;

    public function render(): View
    {
        return view('livewire.tenant.settings.notification-preferences');
    }
}
