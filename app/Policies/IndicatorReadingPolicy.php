<?php

namespace App\Policies;

use App\Models\IndicatorReading;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantAuthority;

/**
 * The four abilities of a figure's life, and they are four on purpose
 * (manual digest §5): recording is delivery work, submitting files it,
 * validating is assurance by a third party, publishing is what makes it
 * quotable outside the platform.
 *
 * The permission split does most of the work: a Consultant holds
 * `indicators.readings.record|submit` and NEVER `.validate`, so "a consultant
 * cannot clear their own figure" needs no runtime check to be true. The
 * identity guard in TransitionIndicatorReadingStatus exists for the case
 * permissions cannot express — a StateAdmin or SuperAdmin who enters a figure
 * on an MDA's behalf and holds every permission there is.
 *
 * `validate` and `publish` are read from the GLOBAL team explicitly. The Data
 * Quality Reviewer is an oversight role working a cross-MDA queue with no
 * tenant bound; asking the workspace team would answer "no" for the very
 * people the role exists for.
 */
class IndicatorReadingPolicy
{
    use ChecksTenantAuthority;

    public function viewAny(User $user): bool
    {
        return $this->permits($user, 'indicators.view');
    }

    public function view(User $user, IndicatorReading $reading): bool
    {
        return $this->permits($user, 'indicators.view', $reading);
    }

    public function create(User $user): bool
    {
        return $this->permits($user, 'indicators.readings.record');
    }

    public function update(User $user, IndicatorReading $reading): bool
    {
        // A figure freezes at submission: everything past draft is corrected
        // by a reviewer sending it back, never by a quiet edit.
        return $reading->status->isEditable()
            && $this->permits($user, 'indicators.readings.record', $reading);
    }

    public function submit(User $user, IndicatorReading $reading): bool
    {
        return $this->permits($user, 'indicators.readings.submit', $reading);
    }

    public function validate(User $user, IndicatorReading $reading): bool
    {
        return $this->reviewerAuthority($user, 'indicators.readings.validate', $reading);
    }

    public function publish(User $user, IndicatorReading $reading): bool
    {
        return $this->reviewerAuthority($user, 'indicators.readings.publish', $reading);
    }

    public function delete(User $user, IndicatorReading $reading): bool
    {
        return $reading->status->isEditable()
            && $this->permits($user, 'indicators.readings.record', $reading);
    }

    /**
     * Assurance authority: the workspace route for an MDA-side holder, or the
     * GLOBAL team for the oversight roles that work the cross-MDA queue. Both
     * are asked, because a state runs data quality from the secretariat while
     * a large ministry may also hold it in-house.
     */
    private function reviewerAuthority(User $user, string $permission, IndicatorReading $reading): bool
    {
        return $this->permits($user, $permission, $reading)
            || $user->holdsGlobalPermission($permission);
    }
}
