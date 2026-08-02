<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The instance's wall clock (rules/coding-standards.md — store UTC, *reason*
 * and display in the instance timezone).
 *
 * A statutory deadline is a wall-clock fact: the monthly return is due at the
 * end of the seventh day IN THE STATE, not at 23:59 UTC. In Africa/Lagos those
 * differ by an hour, and that hour is real: a deadline stored as 23:59:59 UTC
 * falls on 00:59:59 the NEXT morning locally, so between midnight and 1 a.m.
 * the reporting desk, the reminder ladder and the overdue sweep would each
 * disagree about what day it is — and an MDA would be recorded as late for a
 * return it filed before the deadline it was given.
 *
 * Every calendar boundary is therefore built on this clock and converted to the
 * UTC instant that goes in the column. One place, because a second one would
 * eventually be an hour out.
 */
final class InstanceTime
{
    /** The instance's display/reasoning timezone — never a literal in code. */
    public static function zone(): string
    {
        return (string) config('platform.instance.timezone', 'UTC');
    }

    /** The same instant, read on the instance's wall clock. */
    public static function local(DateTimeInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone(self::zone());
    }

    /** Now, on the instance's wall clock. */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::zone());
    }

    /** The UTC instant at which the instance's day containing $moment begins. */
    public static function startOfDay(DateTimeInterface $moment): CarbonImmutable
    {
        return self::local($moment)->startOfDay()->utc();
    }

    /** The UTC instant at which the instance's day containing $moment ends. */
    public static function endOfDay(DateTimeInterface $moment): CarbonImmutable
    {
        return self::local($moment)->endOfDay()->utc();
    }
}
