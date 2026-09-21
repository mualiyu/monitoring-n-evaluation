<?php

namespace App\Actions\Indicators;

use App\Models\IndicatorDefinition;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Creates or revises a STATE LIBRARY entry (oversight, `indicators.library.manage`).
 *
 * The library is global reference data: one wording, one unit, one frequency,
 * used by every MDA — which is the only way the secretariat's annual
 * consolidation against a predetermined indicator list produces comparable
 * numbers (manual digest §4).
 *
 * `code` is the identity and is never rewritten after creation: MDAs quote it
 * in their own returns, and a code that moved would repoint years of figures.
 */
class SaveIndicatorDefinition
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(array $attributes, User $actor, ?IndicatorDefinition $existing = null): IndicatorDefinition
    {
        Gate::forUser($actor)->authorize(
            $existing === null ? 'create' : 'update',
            $existing ?? IndicatorDefinition::class,
        );

        $payload = [
            'name' => $attributes['name'],
            'definition' => $attributes['definition'] ?? null,
            'focus' => $attributes['focus'] ?? null,
            'sector_id' => $attributes['sector_id'] ?? null,
            'unit' => $attributes['unit'],
            'default_measurement_frequency' => $attributes['default_measurement_frequency'],
            'default_target_type' => $attributes['default_target_type'],
            'default_tier' => $attributes['default_tier'] ?? null,
            'data_source' => $attributes['data_source'] ?? null,
            'means_of_verification' => $attributes['means_of_verification'] ?? null,
            'responsible_collector_text' => $attributes['responsible_collector_text'] ?? null,
            'smart_statement' => $attributes['smart_statement'] ?? null,
        ];

        if ($existing !== null) {
            $existing->update($payload);

            return $existing;
        }

        return IndicatorDefinition::query()->create([
            ...$payload,
            'code' => Str::upper(trim((string) $attributes['code'])),
            'is_active' => true,
            'created_by_id' => $actor->id,
        ]);
    }
}
