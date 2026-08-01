<?php

namespace App\Actions\Projects;

use App\Models\Contractor;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Edits a vendor record — oversight only (`contractors.manage`, read from the
 * global team). One MDA must not rewrite a firm another MDA's contracts point
 * at: the registry is shared, so its edits belong to the state.
 *
 * The blacklist fields are refused here. Flipping eligibility is a decision
 * with a mandatory reason and its own audited Action; letting it ride along in
 * a details update is how a firm gets quietly re-admitted.
 */
class UpdateContractor
{
    private const BLACKLIST_FIELDS = ['is_blacklisted', 'blacklist_reason'];

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(Contractor $contractor, User $actor, array $attributes): Contractor
    {
        Gate::forUser($actor)->authorize('update', $contractor);

        foreach (self::BLACKLIST_FIELDS as $field) {
            if (array_key_exists($field, $attributes)) {
                throw new InvalidArgumentException(
                    "[{$field}] is set by BlacklistContractor / LiftContractorBlacklist, which require a stated reason."
                );
            }
        }

        $contractor->update($attributes);

        return $contractor;
    }
}
