<?php

declare(strict_types=1);

namespace App\Livewire\Oversight\Settings;

use App\Livewire\Shared\Concerns\ManagesNotificationPreferences;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Personal notification preferences, in the oversight shell — for accounts
 * that hold oversight authority and no workspace membership, and so could
 * never reach the workspace copy. Same screen: ManagesNotificationPreferences.
 */
#[Layout('layouts::oversight')]
class NotificationPreferences extends Component
{
    use ManagesNotificationPreferences;

    public function render(): View
    {
        return view('livewire.oversight.settings.notification-preferences');
    }
}
