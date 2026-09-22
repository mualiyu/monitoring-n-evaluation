<?php

namespace App\Enums;

/**
 * The marker vocabulary of the GIS dashboard.
 *
 * Nine lifecycle statuses are too many hues for one map to stay legible, and
 * the question a map answers is "where is delivery healthy and where is it
 * not" — so statuses collapse into six categories, and OVERDUE (a derived
 * condition, not a status) outranks the status it sits on, because a late
 * in-progress road is the pin a director is looking for.
 *
 * Every category carries an icon as well as a tone: markers and the legend
 * both show the glyph, so the map never conveys status by colour alone.
 */
enum ProjectMapCategory: string
{
    case Pipeline = 'pipeline';
    case InProgress = 'in_progress';
    case Overdue = 'overdue';
    case Suspended = 'suspended';
    case Finished = 'finished';
    case Cancelled = 'cancelled';

    public static function for(ProjectStatus $status, bool $overdue): self
    {
        if ($overdue) {
            return self::Overdue;
        }

        return match ($status) {
            ProjectStatus::Draft, ProjectStatus::Awarded, ProjectStatus::Mobilized => self::Pipeline,
            ProjectStatus::InProgress => self::InProgress,
            ProjectStatus::Suspended => self::Suspended,
            ProjectStatus::Completed, ProjectStatus::Certified, ProjectStatus::Closed => self::Finished,
            ProjectStatus::Cancelled => self::Cancelled,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pipeline => __('Not yet started'),
            self::InProgress => __('In progress'),
            self::Overdue => __('Overdue'),
            self::Suspended => __('Suspended'),
            self::Finished => __('Completed'),
            self::Cancelled => __('Cancelled'),
        };
    }

    /** An <x-ui.icon> name. */
    public function icon(): string
    {
        return match ($this) {
            self::Pipeline => 'clock',
            self::InProgress => 'arrow-path',
            self::Overdue => 'exclamation-triangle',
            self::Suspended => 'pause-circle',
            self::Finished => 'check-circle',
            self::Cancelled => 'x-mark',
        };
    }

    /**
     * The semantic token family the marker is painted from — a token, never a
     * hex, so a tenant's brand override and dark mode re-skin the map too.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Pipeline => 'info',
            self::InProgress => 'brand',
            self::Overdue => 'critical',
            self::Suspended => 'warning',
            self::Finished => 'positive',
            self::Cancelled => 'neutral',
        };
    }
}
