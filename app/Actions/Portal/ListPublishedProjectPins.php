<?php

namespace App\Actions\Portal;

use App\Models\Project;
use App\Support\Publishing\PublicProjectPayload;
use App\Tenancy\PortalRead;

/**
 * Map pins for /map — one pin per SITE of a published project.
 *
 * The pin carries exactly what a marker popup renders: title, entity, sector,
 * status, progress, the site label and its coordinates, plus the ULID the
 * popup links to. It is built from PublicProjectPayload like every other
 * portal read, because a map is the surface where "just this one extra field"
 * is most tempting and least visible in review — a JSON blob in a data
 * attribute is not something anybody re-reads.
 *
 * Capped: a map that ships ten thousand markers to a ₦5,000 Android phone is
 * not a map. Beyond the cap the screen tells the visitor to filter, rather
 * than silently drawing a subset.
 */
class ListPublishedProjectPins
{
    /** Maximum markers served to one map view. */
    public const MAX_PINS = 500;

    /** @return list<array<string, mixed>> */
    public function __invoke(): array
    {
        return (new PortalRead)(function (): array {
            $projects = PublicProjectPayload::forPortal(Project::query())
                ->whereHas('locations', fn ($location) => $location
                    ->whereNotNull('latitude')
                    ->whereNotNull('longitude'))
                ->orderByDesc('published_at')
                ->limit(self::MAX_PINS)
                ->get();

            $pins = [];

            foreach (PublicProjectPayload::collect($projects) as $payload) {
                /** @var list<array<string, mixed>> $locations */
                $locations = $payload['locations'];

                foreach ($locations as $location) {
                    if ($location['latitude'] === null || $location['longitude'] === null) {
                        continue;
                    }

                    $pins[] = [
                        'ulid' => $payload['ulid'],
                        'title' => $payload['title'],
                        'entity' => $payload['entity'],
                        'sector' => $payload['sector'],
                        'status' => $payload['status'],
                        'status_label' => $payload['status_label'],
                        'progress' => $payload['physical_progress'],
                        'site' => $location['site_name'],
                        'lga' => $location['lga'],
                        'lat' => (float) $location['latitude'],
                        'lng' => (float) $location['longitude'],
                    ];
                }
            }

            return $pins;
        });
    }
}
