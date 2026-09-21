<?php

namespace App\Enums;

/**
 * Lifecycle of a state consolidation (plan §4 "Reporting & consolidation";
 * manual digest §4 — the M&E Secretariat consolidates MDA returns for the
 * Commissioner, and §1 — the reporting chain ends at the state, not the MDA).
 *
 * App\Actions\Consolidation\TransitionConsolidationStatus is the ONLY writer
 * of ConsolidatedReport::$status and consults this table first: an impossible
 * move is impossible for everyone, before any permission is considered.
 *
 * Read the five states as the secretariat's actual desk:
 *
 *  - `draft`     the window has been opened for a roll-up. No figures yet, so
 *                nothing can be reviewed — the narrative skeleton exists and
 *                nothing else.
 *  - `compiling` figures have been pulled from the MDAs and the secretariat is
 *                writing the narrative around them. Recompiling is allowed
 *                here (and only here plus `draft`): a late MDA return must be
 *                able to reach a report that has not yet been sent up.
 *  - `in_review` sent up the chain. The figures are frozen to editing but not
 *                yet to change — a reviewer may send it back.
 *  - `approved`  signed off. THIS is where the snapshot freezes: an APR whose
 *                numbers move when an MDA edits last quarter's return is not
 *                an APR, it is a dashboard with a title page.
 *  - `published` released. Terminal. A figure that has been quoted outside the
 *                platform is corrected by issuing the NEXT consolidation,
 *                never by editing the one people already hold.
 */
enum ConsolidationStatus: string
{
    case Draft = 'draft';
    case Compiling = 'compiling';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Published = 'published';

    /**
     * States reachable from this one. Note what is NOT here:
     * `draft → in_review` (a consolidation with no figures is not reviewable),
     * `approved → compiling` (the snapshot has been taken and signed; a
     * correction is a new consolidation), and anything at all out of
     * `published`.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Compiling],
            // Back to draft when a compile fails or the secretariat abandons
            // the figures and starts the window again.
            self::Compiling => [self::Draft, self::InReview],
            // A return lands on `compiling`, not `draft`: that is where the
            // narrative lives, and the figures the reviewer questioned are
            // still attached to argue with.
            self::InReview => [self::Compiling, self::Approved],
            self::Approved => [self::Published],
            self::Published => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /** Whether the secretariat may still edit narrative sections and recompile. */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Compiling;
    }

    /** Whether the figures behind this consolidation have been frozen. */
    public function isFrozen(): bool
    {
        return $this === self::Approved || $this === self::Published;
    }

    /** Whether the consolidation may be exported as a finished artifact. */
    public function isSigned(): bool
    {
        return $this->isFrozen();
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Compiling => __('Compiling'),
            self::InReview => __('In review'),
            self::Approved => __('Approved'),
            self::Published => __('Published'),
        };
    }

    /** Status is icon + text, never colour alone (rules/ui-design-system.md). */
    public function icon(): string
    {
        return match ($this) {
            self::Draft => 'pencil-square',
            self::Compiling => 'arrow-path',
            self::InReview => 'eye',
            self::Approved => 'check-circle',
            self::Published => 'globe',
        };
    }

    /**
     * The <x-ui.badge> key this state renders as. The badge vocabulary is
     * shared platform-wide, so a state with no pill of its own borrows the
     * nearest one rather than inventing a chip the component cannot colour.
     */
    public function badge(): string
    {
        return match ($this) {
            self::Draft => 'draft',
            self::Compiling => 'submitted',
            self::InReview => 'under_review',
            self::Approved => 'approved',
            self::Published => 'certified',
        };
    }
}
