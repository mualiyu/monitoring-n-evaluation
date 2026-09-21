<?php

namespace App\Policies;

use App\Models\IndicatorDefinition;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantAuthority;

/**
 * The indicator library is GLOBAL — there is no tenant to match, and that is
 * the point (see IndicatorDefinition's docblock). Authority splits in two, as
 * it does for the contractor registry:
 *
 *  - **reading** it is every M&E user's business: an MDA building a framework
 *    picks from the state list, so `indicators.view` is enough;
 *  - **writing** it is a state act, read from the GLOBAL team. One MDA must
 *    not reword the definition of a measure every other ministry reports
 *    against — that is what the manual's Q4 indicator retreat is for, and
 *    `indicators.library.manage` is seeded to the secretariat alone.
 */
class IndicatorDefinitionPolicy
{
    use ChecksTenantAuthority;

    public function viewAny(User $user): bool
    {
        // No record argument anywhere in this policy: the library is global,
        // so there is no tenant to match — only authority to weigh.
        return $this->permits($user, 'indicators.view');
    }

    public function view(User $user, IndicatorDefinition $definition): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->holdsGlobalPermission('indicators.library.manage');
    }

    public function update(User $user, IndicatorDefinition $definition): bool
    {
        return $user->holdsGlobalPermission('indicators.library.manage');
    }

    public function delete(User $user, IndicatorDefinition $definition): bool
    {
        // Deliberately the same authority as update and nothing more: the
        // library is retired, never deleted (RetireIndicatorDefinition), so
        // nothing calls this — it exists so an accidental future ->delete()
        // is refused rather than silently allowed by a missing ability.
        return false;
    }
}
