<?php

namespace App\Support\Publishing;

use App\Models\Project;
use App\Models\ProjectLocation;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * THE whitelist. Everything the public portal renders about a project passes
 * through this class and comes out as a plain array of the keys listed in
 * self::FIELDS — the portal views never touch a Project model at all.
 *
 * That is deliberate and it is the security control, not a formatting
 * convenience. A column added to `projects` next quarter — a contractor's bank
 * details, an internal risk note, the name of the officer who flagged a
 * contractor — cannot reach a citizen's browser by accident, because there is
 * no code path from an Eloquent attribute to a portal blade. Making a field
 * public is a deliberate edit to this file and shows up in a diff as one.
 *
 * WHAT IS PUBLISHED, and why (the field-level decision the security rules ask
 * for — argue with these lines, not with a query somewhere):
 *
 *  - Identity and scope: reference, title, description, goal, objectives,
 *    sector, type, the delivering entity and any supervising agency. This is
 *    what "a public project" means; withholding it would make the portal
 *    pointless.
 *  - Delivery: status, physical progress, the four dates. The portal's promise
 *    is "what has actually been built", and progress without a date is a
 *    number nobody can hold anyone to.
 *  - Money: the CONTRACT value and expenditure to date, exactly, plus the
 *    derived financial progress. Award sums of public works are already public
 *    record under Nigerian procurement law, and "what was contracted vs what
 *    has been paid" is the single most requested figure on a transparency
 *    portal.
 *  - Contractor NAME only (not its registration, contacts, or blacklist
 *    history). Who the state hired is public; the vendor's file is not.
 *  - Locations: site name, LGA, ward and coordinates — the map exists for
 *    this, and a citizen must be able to tell whether the project on the
 *    portal is the one at the end of their street.
 *  - Photographs: uuid + caption of the `project_photos` collection ONLY, and
 *    even then served through the portal's own re-encoding route, never as a
 *    path on disk.
 *
 * WHAT IS DELIBERATELY WITHHELD:
 *
 *  - budget_allocation / budget_code — the internal appropriation line. A
 *    budget code is a key into the state's finance system, and an allocation
 *    printed next to a contract sum is read as fraud when it is usually
 *    phasing.
 *  - Every person: created_by_id, manager_id, published_by_id. Naming the
 *    individual civil servant responsible for a contested project on a public
 *    page is a safety decision, and it is not one this platform makes by
 *    default.
 *  - Internal lifecycle stamps: reporting_frequency, status_changed_at,
 *    mid_term_flagged_at, post_completion_review_due_at. These drive the
 *    deadline engine; on a public page they are noise that invites
 *    misreading.
 *  - The database id. The portal addresses projects by ULID, so nothing on it
 *    tells a scraper how many projects an MDA has.
 */
final readonly class PublicProjectPayload implements Arrayable
{
    /**
     * The exact key set of the public payload. A test asserts that
     * toArray() produces these keys and nothing else, so adding a column to
     * `projects` can never quietly widen what the portal serves.
     *
     * @var list<string>
     */
    public const FIELDS = [
        'ulid',
        'reference',
        'title',
        'description',
        'goal',
        'objectives',
        'entity',
        'supervising_agency',
        'sector',
        'type',
        'status',
        'status_label',
        'physical_progress',
        'contract_value',
        'contract_value_formatted',
        'expenditure',
        'expenditure_formatted',
        'financial_progress',
        'contractor',
        'start_date',
        'expected_end_date',
        'revised_end_date',
        'actual_end_date',
        'locations',
        'primary_location',
        'photos',
        'published_at',
    ];

    /**
     * The relations a payload needs. Every portal query eager-loads exactly
     * this: the portal runs unauthenticated on a public URL, so an N+1 there
     * is not a slow page, it is the cheapest denial of service on the platform.
     *
     * @var list<string>
     */
    public const RELATIONS = [
        'tenant:id,name',
        'sector:id,name',
        'supervisingAgency:id,name',
        'locations.lga:id,name',
        'locations.ward:id,name',
        'contracts.contractor:id,name',
        'media',
    ];

    /** @param  array<string, mixed>  $data */
    private function __construct(private array $data) {}

    public static function for(Project $project): self
    {
        return new self([
            'ulid' => $project->ulid,
            'reference' => $project->reference,
            'title' => $project->title,
            'description' => $project->description,
            'goal' => $project->goal,
            'objectives' => $project->objectives,
            'entity' => $project->tenant?->name,
            'supervising_agency' => $project->supervisingAgency?->name
                ?? $project->supervising_agency_name,
            'sector' => $project->sector?->name,
            'type' => $project->type->label(),
            'status' => $project->status->value,
            'status_label' => $project->status->label(),
            'physical_progress' => (float) $project->physical_progress,
            'contract_value' => $project->contract_value_total?->toDecimalString(),
            'contract_value_formatted' => $project->contract_value_total?->format(),
            'expenditure' => $project->expenditure_to_date->toDecimalString(),
            'expenditure_formatted' => $project->expenditure_to_date->format(),
            'financial_progress' => $project->financial_progress,
            'contractor' => self::contractorName($project),
            'start_date' => $project->start_date?->toDateString(),
            'expected_end_date' => $project->expected_end_date?->toDateString(),
            'revised_end_date' => $project->revised_end_date?->toDateString(),
            'actual_end_date' => $project->actual_end_date?->toDateString(),
            'locations' => self::locations($project),
            'primary_location' => self::primaryLocation($project),
            'photos' => self::photos($project),
            'published_at' => $project->published_at?->toDateString(),
        ]);
    }

    /**
     * The published-only predicate, in one place. Every portal read applies
     * it; nothing else in the codebase decides what "published" means.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public static function publishedOnly(Builder $query): Builder
    {
        return $query->whereNotNull('published_at');
    }

    /**
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public static function forPortal(Builder $query): Builder
    {
        return self::publishedOnly($query)->with(self::RELATIONS);
    }

    /**
     * Project a page of Project models into a page of public payloads. The
     * paginator the portal hands to a view therefore contains arrays, not
     * models — there is no `$project->whatever` a blade could reach for.
     *
     * @param  LengthAwarePaginator<int, Project>  $projects
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public static function paginator(LengthAwarePaginator $projects): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            $projects->getCollection()->map(fn (Project $project): array => self::for($project)->toArray()),
            $projects->total(),
            $projects->perPage(),
            $projects->currentPage(),
            [
                'path' => $projects->path(),
                'pageName' => $projects->getPageName(),
            ],
        );
    }

    /**
     * @param  iterable<int, Project>  $projects
     * @return list<array<string, mixed>>
     */
    public static function collect(iterable $projects): array
    {
        $payloads = [];

        foreach ($projects as $project) {
            $payloads[] = self::for($project)->toArray();
        }

        return $payloads;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    /** @return mixed */
    public function get(string $field)
    {
        return $this->data[$field] ?? null;
    }

    /**
     * The name on the largest active contract. One name, never the vendor
     * file: `Contractor` carries registration numbers, contacts and blacklist
     * history, none of which is the public's business on a project page.
     */
    private static function contractorName(Project $project): ?string
    {
        if (! $project->relationLoaded('contracts')) {
            return null;
        }

        return $project->contracts
            ->map(fn ($contract) => $contract->contractor?->name)
            ->filter()
            ->first();
    }

    /**
     * @return list<array{site_name: string|null, lga: string|null, ward: string|null, latitude: string|null, longitude: string|null, is_primary: bool}>
     */
    private static function locations(Project $project): array
    {
        if (! $project->relationLoaded('locations')) {
            return [];
        }

        /** @var Collection<int, ProjectLocation> $locations */
        $locations = $project->locations;

        return $locations->map(fn (ProjectLocation $location): array => [
            'site_name' => $location->site_name,
            'lga' => $location->lga?->name,
            'ward' => $location->ward?->name,
            // Strings, never floats: coordinates are printed on inspection
            // reports and must round-trip unchanged (see ProjectLocation).
            'latitude' => $location->latitude,
            'longitude' => $location->longitude,
            'is_primary' => $location->is_primary,
        ])->values()->all();
    }

    /** @return array<string, mixed>|null */
    private static function primaryLocation(Project $project): ?array
    {
        $locations = self::locations($project);

        foreach ($locations as $location) {
            if ($location['is_primary']) {
                return $location;
            }
        }

        return $locations[0] ?? null;
    }

    /**
     * Site photography: uuid + caption only. The uuid is the handle the
     * portal's own photo route re-encodes through — there is no disk path, no
     * signed original and no EXIF in this payload, so a GPS-stamped original
     * cannot leave the private disk by way of a public page.
     *
     * @return list<array{uuid: string, caption: string}>
     */
    private static function photos(Project $project): array
    {
        if (! $project->relationLoaded('media')) {
            return [];
        }

        return $project->getMedia('project_photos')
            ->map(fn ($media): array => [
                'uuid' => (string) $media->uuid,
                'caption' => (string) $media->name,
            ])
            ->values()
            ->all();
    }
}
