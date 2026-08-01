<?php

namespace App\Actions\Projects;

use App\Models\Contractor;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Restores a firm's eligibility. The mirror of BlacklistContractor and equally
 * deliberate: re-admitting a barred contractor is the decision most worth
 * having a name and a reason attached to, which is why it cannot happen
 * through a details update.
 */
class LiftContractorBlacklist
{
    public function __invoke(Contractor $contractor, User $actor, string $reason): Contractor
    {
        Gate::forUser($actor)->authorize('blacklist', $contractor);

        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Lifting a blacklisting requires a stated reason.');
        }

        $contractor->update([
            'is_blacklisted' => false,
            'blacklist_reason' => null,
        ]);

        // The reason the bar was lifted only exists here — blacklist_reason is
        // cleared, so without this record the register would show a clean firm
        // and no history of why.
        activity('contractors')
            ->performedOn($contractor)
            ->causedBy($actor)
            ->withProperties(['reason' => $reason])
            ->log('blacklist_lifted');

        return $contractor;
    }
}
