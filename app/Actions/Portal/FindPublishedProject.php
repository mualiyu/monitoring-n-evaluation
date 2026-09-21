<?php

namespace App\Actions\Portal;

use App\Models\Project;
use App\Support\Publishing\PublicProjectPayload;
use App\Tenancy\PortalRead;

/**
 * Resolve one published project for its public page, BY ULID.
 *
 * Never by auto-increment id and never through route-model binding: implicit
 * binding would resolve a Project under the tenant scope, which on the apex
 * domain has no tenant bound and would answer a 500 instead of a 404. Worse,
 * a binder that resolved would hand the page an unpublished project and rely
 * on the view to hide it.
 *
 * The published filter therefore lives in the lookup itself. An unpublished
 * project, and a project unpublished five seconds ago, are both simply "not
 * found" — which is what the portal should say about a record the state has
 * not opened.
 */
class FindPublishedProject
{
    /** @return array<string, mixed>|null */
    public function __invoke(string $ulid): ?array
    {
        return (new PortalRead)(function () use ($ulid): ?array {
            $project = PublicProjectPayload::forPortal(Project::query())
                ->where('ulid', $ulid)
                ->first();

            return $project instanceof Project
                ? PublicProjectPayload::for($project)->toArray()
                : null;
        });
    }

    /**
     * The internal key, for the two callers that need to join on it (the
     * feedback thread and a feedback submission). It is resolved HERE, from
     * the public ULID, under the published predicate — so no id supplied by a
     * visitor is ever trusted, and feedback cannot be attached to a project
     * the state has not published.
     */
    public function id(string $ulid): ?int
    {
        return (new PortalRead)(function () use ($ulid): ?int {
            $id = PublicProjectPayload::publishedOnly(Project::query())
                ->where('ulid', $ulid)
                ->value('id');

            return $id === null ? null : (int) $id;
        });
    }

    /**
     * The model itself — for the one caller that needs media off it (the
     * portal photo route). Still published-only, still ULID-only.
     */
    public function model(string $ulid): ?Project
    {
        return (new PortalRead)(fn (): ?Project => PublicProjectPayload::publishedOnly(Project::query())
            ->with('media')
            ->where('ulid', $ulid)
            ->first());
    }
}
