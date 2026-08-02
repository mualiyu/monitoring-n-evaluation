<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\TenantSetting;
use App\Tenancy\CurrentTenant;

/**
 * The configuration chain the design mandates for every domain number
 * (projects-module.md §0.1): tenant override → instance value → config default.
 *
 * A state may run a 12-month post-completion window where another runs 6, and
 * one MDA may need a different mid-term trigger from its neighbour. Actions
 * therefore never read `config('platform.monitoring.*')` directly — they ask
 * here, so a setting row can override a deployment default without a release.
 *
 * Deliberately not memoised: an Action that reads a setting twice in one
 * request pays two cheap indexed lookups, which is a better trade than a cache
 * that serves a value a settings screen just changed.
 */
class SettingsRepository
{
    public function get(string $group, string $key, mixed $default = null): mixed
    {
        $current = app(CurrentTenant::class);

        if ($current->bound()) {
            // TenantSetting is tenant-scoped by the global scope — the lookup
            // can only ever see the bound MDA's own overrides.
            $override = TenantSetting::query()
                ->where('group', $group)
                ->where('key', $key)
                ->first();

            if ($override !== null && $override->value !== null) {
                return $override->value;
            }
        }

        $instance = Setting::query()
            ->where('group', $group)
            ->where('key', $key)
            ->first();

        if ($instance !== null && $instance->value !== null) {
            return $instance->value;
        }

        return config("platform.{$group}.{$key}", $default);
    }

    public function int(string $group, string $key, int $default): int
    {
        $value = $this->get($group, $key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $group, string $key, bool $default): bool
    {
        $value = $this->get($group, $key, $default);

        return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOL);
    }

    public function string(string $group, string $key, string $default): string
    {
        $value = $this->get($group, $key, $default);

        return is_string($value) ? $value : $default;
    }

    /**
     * A list of whole numbers — the reminder ladder and the escalation ladder
     * (progress-reporting.md §0.1) are both configured this way. Settings rows
     * cast their value as JSON, so a stored `[7, 3, 1]` arrives as an array of
     * ints; a malformed override falls back to the default rather than
     * silencing the deadline engine.
     *
     * @param  list<int>  $default
     * @return list<int>
     */
    public function ints(string $group, string $key, array $default): array
    {
        $value = $this->get($group, $key, $default);

        if (! is_array($value)) {
            return $default;
        }

        $ints = [];

        foreach ($value as $item) {
            if (! is_numeric($item)) {
                return $default;
            }

            $ints[] = (int) $item;
        }

        return $ints === [] ? [] : $ints;
    }

    /**
     * A list of strings — e.g. which project statuses owe reports.
     *
     * @param  list<string>  $default
     * @return list<string>
     */
    public function strings(string $group, string $key, array $default): array
    {
        $value = $this->get($group, $key, $default);

        if (! is_array($value)) {
            return $default;
        }

        $strings = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                return $default;
            }

            $strings[] = $item;
        }

        return $strings;
    }
}
