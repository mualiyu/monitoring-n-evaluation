<?php

namespace App\Policies;

use App\Models\ExceptionReport;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantAuthority;
use App\Tenancy\CurrentTenant;

/**
 * Permission AND tenant match (the shared trait), plus the visibility
 * narrowing every project-child record gets: a consultant sees deviation
 * reports on the projects they are assigned to and no others.
 *
 * `acknowledge` and `resolve` share `exceptions.resolve` deliberately. Both
 * are statements about whether the deviation stands, and the matrix gives that
 * to MDA staff while leaving `exceptions.create` open to the field roles who
 * witness a critical incident. An inspector may report that a bridge pier has
 * cracked; whether that report stands is not theirs to close.
 */
class ExceptionReportPolicy
{
    use ChecksTenantAuthority;

    public function viewAny(User $user): bool
    {
        return $this->permits($user, 'exceptions.view');
    }

    public function view(User $user, ExceptionReport $report): bool
    {
        if (! $this->permits($user, 'exceptions.view', $report)) {
            return false;
        }

        return ! app(CurrentTenant::class)->bound() || $this->isVisible($user, $report);
    }

    public function create(User $user): bool
    {
        return $this->permits($user, 'exceptions.create');
    }

    /** "Seen, and it is real." */
    public function acknowledge(User $user, ExceptionReport $report): bool
    {
        return $this->permits($user, 'exceptions.resolve', $report);
    }

    /** "The deviation no longer stands" — with a note saying why. */
    public function resolve(User $user, ExceptionReport $report): bool
    {
        return $this->permits($user, 'exceptions.resolve', $report);
    }

    private function isVisible(User $user, ExceptionReport $report): bool
    {
        return ExceptionReport::query()->visibleTo($user)->whereKey($report->getKey())->exists();
    }
}
