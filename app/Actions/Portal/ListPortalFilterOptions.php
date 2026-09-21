<?php

namespace App\Actions\Portal;

use App\Enums\ProjectStatus;
use App\Models\Lga;
use App\Models\Project;
use App\Models\Sector;
use App\Support\Publishing\PublicProjectPayload;
use App\Tenancy\PortalRead;
use Illuminate\Database\Eloquent\Builder;

/**
 * The options in the portal's filter bar.
 *
 * Derived from PUBLISHED projects, never from the reference tables in full.
 * Two reasons, and the second is the important one:
 *
 *  - Usability: a state has 20-odd LGAs and a dozen sectors. Offering all of
 *    them against three published projects gives a citizen eighteen ways to
 *    reach an empty list.
 *  - Disclosure: a filter list IS data. "Sector: Security" appearing in a
 *    dropdown tells a reader the state is running security projects even when
 *    none of them is published. The filter bar therefore describes exactly the
 *    set the list can show, and nothing beyond it.
 */
class ListPortalFilterOptions
{
    /**
     * @return array{sectors: array<int, string>, lgas: array<int, string>, statuses: array<string, string>}
     */
    public function __invoke(): array
    {
        return (new PortalRead)(function (): array {
            $published = fn (Builder $query): Builder => PublicProjectPayload::publishedOnly($query);

            /** @var array<int, string> $sectors */
            $sectors = Sector::query()
                ->whereHas('projects', $published)
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all();

            /** @var array<int, string> $lgas */
            $lgas = Lga::query()
                ->whereHas(
                    'projectLocations',
                    fn (Builder $location) => $location->whereHas('project', $published),
                )
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all();

            /** @var list<string> $present */
            $present = $published(Project::query())
                ->distinct()
                ->orderBy('status')
                ->pluck('status')
                ->map(fn (mixed $status): string => $status instanceof ProjectStatus ? $status->value : (string) $status)
                ->all();

            $statuses = [];

            foreach ($present as $value) {
                $case = ProjectStatus::tryFrom($value);

                if ($case instanceof ProjectStatus) {
                    $statuses[$case->value] = $case->label();
                }
            }

            return ['sectors' => $sectors, 'lgas' => $lgas, 'statuses' => $statuses];
        });
    }
}
