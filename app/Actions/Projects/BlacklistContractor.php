<?php

namespace App\Actions\Projects;

use App\Models\Contractor;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Bars a firm from new awards state-wide. Oversight only, and the reason is
 * mandatory: this is a decision with commercial consequences for a company,
 * and "why" is the first thing asked when it is challenged.
 *
 * It does NOT touch existing contracts — work already awarded is governed by
 * its contract, and terminating one is a separate act. AwardContract is where
 * the flag bites: a blacklisted firm cannot receive a new award in any MDA,
 * which is the whole reason the registry is state-wide.
 */
class BlacklistContractor
{
    public function __invoke(Contractor $contractor, User $actor, string $reason): Contractor
    {
        Gate::forUser($actor)->authorize('blacklist', $contractor);

        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Blacklisting a firm requires a stated reason.');
        }

        $contractor->update([
            'is_blacklisted' => true,
            'blacklist_reason' => $reason,
        ]);

        return $contractor;
    }
}
