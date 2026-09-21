<?php

namespace App\Notifications\Issues\Concerns;

use App\Models\ExceptionReport;
use App\Models\Issue;
use App\Models\Tenant;
use App\Support\SurfaceUrl;

/**
 * Deep links into the MDA workspace, built for a QUEUE WORKER.
 *
 * Two halves, and both matter. The host comes from SurfaceUrl, which reads
 * config rather than the current request: a worker has no request host, and
 * falling back to APP_URL is how a notification ends up pointing at the wrong
 * subdomain. The path comes from route(), which fails loudly on a missing
 * route name or a wrong binding key — a hand-built '/issues/'.$id string would
 * 404 silently for as long as nobody clicked it.
 *
 * `absolute: false` is what lets the two be combined: ResolveTenant supplies
 * the {tenant} URL default inside a request and there is none here, so the
 * slug is passed explicitly and only the path is generated.
 */
trait LinksToIssueScreens
{
    protected function issueUrl(Issue $issue, Tenant $tenant): string
    {
        return SurfaceUrl::base($tenant).route(
            'tenant.issues.show',
            ['tenant' => $tenant->slug, 'issue' => $issue->ulid],
            absolute: false,
        );
    }

    protected function exceptionUrl(ExceptionReport $report, Tenant $tenant): string
    {
        return SurfaceUrl::base($tenant).route(
            'tenant.exceptions.show',
            ['tenant' => $tenant->slug, 'exceptionReport' => $report->ulid],
            absolute: false,
        );
    }
}
