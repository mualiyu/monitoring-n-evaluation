<?php

namespace App\Policies;

use App\Models\Indicator;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantAuthority;

/**
 * Consultants read the framework they report against but never edit it, and
 * activation — which is what unlocks readings — is an M&E act, not a
 * contractor's.
 */
class IndicatorPolicy
{
    use ChecksTenantAuthority;

    public function viewAny(User $user): bool
    {
        return $this->permits($user, 'indicators.view');
    }

    public function view(User $user, Indicator $indicator): bool
    {
        return $this->permits($user, 'indicators.view', $indicator);
    }

    public function create(User $user): bool
    {
        return $this->permits($user, 'indicators.create');
    }

    public function update(User $user, Indicator $indicator): bool
    {
        return $this->permits($user, 'indicators.update', $indicator);
    }

    public function activate(User $user, Indicator $indicator): bool
    {
        return $this->permits($user, 'indicators.activate', $indicator);
    }
}
