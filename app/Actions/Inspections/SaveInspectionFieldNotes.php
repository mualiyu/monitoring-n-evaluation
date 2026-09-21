<?php

namespace App\Actions\Inspections;

use App\Exceptions\Inspections\InspectionRuleViolation;
use App\Models\ProjectLocation;
use App\Models\SiteInspection;
use App\Models\User;
use App\Support\SettingsRepository;
use Illuminate\Support\Facades\Gate;

/**
 * The autosave target of the conduct form.
 *
 * There is no separate draft store: the `site_inspections` row IS the draft,
 * and this Action updates it and stamps `autosaved_at`. That matters more here
 * than anywhere else in the platform — a field monitor files these on an old
 * Android over 3G, and a form that holds an hour of observation in browser
 * memory until a "Save" button is pressed loses it the first time a tunnel,
 * a battery or a dropped connection intervenes.
 *
 * The GPS fix is written here too, with its geofence judgement computed ONCE
 * and stored. `inspections.geofence_metres` can be retuned by the state; a
 * report printed in three years must show the judgement made on the day, not
 * the one today's policy would reach.
 */
class SaveInspectionFieldNotes
{
    /**
     * @param  array<string, mixed>  $attributes  narrative + observation fields only
     * @param  array{latitude: string|float, longitude: string|float, accuracy?: int|null}|null  $position
     */
    public function __invoke(
        SiteInspection $inspection,
        User $actor,
        array $attributes,
        ?array $position = null,
    ): SiteInspection {
        Gate::forUser($actor)->authorize('conduct', $inspection);

        if (! $inspection->isEditable()) {
            throw InspectionRuleViolation::notEditable($inspection->status);
        }

        $allowed = array_intersect_key($attributes, array_flip([
            'team', 'physical_progress_observed', 'outcome', 'risk_flags',
            'objectives', 'people_met', 'methods', 'findings',
            'comparison_with_previous', 'conclusions', 'recommendations',
        ]));

        if (array_key_exists('physical_progress_observed', $allowed)
            && $allowed['physical_progress_observed'] !== null
            && $allowed['physical_progress_observed'] !== '') {
            $this->assertPercentage((string) $allowed['physical_progress_observed']);
        } elseif (array_key_exists('physical_progress_observed', $allowed)) {
            $allowed['physical_progress_observed'] = null;
        }

        $inspection->fill($allowed);

        $stamps = ['autosaved_at' => now()];

        if ($position !== null) {
            $stamps += $this->positionStamps($inspection, $position);
        }

        // forceFill for the stamps: the fix, its geofence judgement and the
        // autosave marker are chokepoint-adjacent columns and deliberately not
        // fillable, so no payload can assert a position the browser never
        // reported.
        $inspection->forceFill($stamps)->save();

        return $inspection;
    }

    /**
     * The captured fix plus the distance from the project's recorded site.
     *
     * @param  array{latitude: string|float, longitude: string|float, accuracy?: int|null}  $position
     * @return array<string, mixed>
     */
    private function positionStamps(SiteInspection $inspection, array $position): array
    {
        $latitude = (float) $position['latitude'];
        $longitude = (float) $position['longitude'];

        if (abs($latitude) > 90 || abs($longitude) > 180) {
            throw InspectionRuleViolation::coordinatesOutOfRange();
        }

        $distance = $this->distanceToSite($inspection, $latitude, $longitude);
        $fence = app(SettingsRepository::class)->int('inspections', 'geofence_metres', 2000);

        return [
            // Decimal strings, never floats: coordinates are printed on a
            // government report and must round-trip unchanged.
            'latitude' => number_format($latitude, 7, '.', ''),
            'longitude' => number_format($longitude, 7, '.', ''),
            'gps_accuracy_metres' => isset($position['accuracy']) ? (int) $position['accuracy'] : null,
            'gps_captured_at' => now(),
            'geofence_distance_metres' => $distance,
            // 0 disables the check, per config/platform.php. A project with no
            // recorded coordinates cannot breach a fence it has no centre for
            // — flagging those would train officers to ignore the flag.
            'geofence_breached' => $fence > 0 && $distance !== null && $distance > $fence,
        ];
    }

    /**
     * Great-circle distance in metres between the fix and the project's site.
     *
     * Haversine on a spherical earth: at the scale this is used for — is the
     * inspector within a couple of kilometres of the site they are reporting
     * on — the ~0.3% error against an ellipsoidal model is three metres in a
     * kilometre, far inside a phone's own accuracy. Returns null when the
     * project has no coordinates to compare against.
     */
    private function distanceToSite(SiteInspection $inspection, float $latitude, float $longitude): ?int
    {
        $site = $inspection->project_location_id !== null
            ? $inspection->loadMissing('location')->location
            : $this->primaryLocation($inspection);

        if ($site === null || $site->latitude === null || $site->longitude === null) {
            return null;
        }

        $earthRadius = 6_371_000;

        $latitudeDelta = deg2rad((float) $site->latitude - $latitude);
        $longitudeDelta = deg2rad((float) $site->longitude - $longitude);

        $a = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($latitude)) * cos(deg2rad((float) $site->latitude))
            * sin($longitudeDelta / 2) ** 2;

        return (int) round($earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    private function primaryLocation(SiteInspection $inspection): ?ProjectLocation
    {
        return $inspection->loadMissing('project.primaryLocation')->project->primaryLocation;
    }

    private function assertPercentage(string $value): void
    {
        if (! is_numeric($value)) {
            throw InspectionRuleViolation::progressOutOfRange($value);
        }

        // decimal(5,2) — integer basis points keep the range test off floats.
        $basisPoints = (int) round(((float) $value) * 100);

        if ($basisPoints < 0 || $basisPoints > 10_000) {
            throw InspectionRuleViolation::progressOutOfRange($value);
        }
    }
}
