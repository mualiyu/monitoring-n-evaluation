<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * "Take me to the thing this is about."
 *
 * A notification that cannot be clicked is a notification somebody has to go
 * and look for, which in practice means it is ignored. Every database
 * notification on this platform already carries the public id of the record it
 * concerns, so the link is derivable rather than stored.
 *
 * MOST SPECIFIC WINS. A returned progress report is about the RETURN, not
 * about the project it hangs off, so `report_ulid` is tried before
 * `project_ulid`; the ordering below is the whole rule.
 *
 * A RECORD'S OWN PAGE BEATS ANY REGISTER, AND A REGISTER BEATS NOTHING. Some
 * records have no detail screen (recommendations, certificates, obligations),
 * and some have one only on the workspace (evaluations, work plans). Those
 * rows used to render as dead text. Now: the most specific record page that
 * exists on this surface wins; failing every page, the most specific record's
 * register does — the follow-up register for a recommendation, the state
 * evaluations register for an evaluation seen from oversight.
 *
 * EVERY TARGET IS GUARDED BY Route::has(). Normally hand-checking a route name
 * is the wrong instinct — route() failing loudly is a feature. Here it is
 * deliberate: modules land on their own schedule, so a notification class can
 * exist before the screen it points at does, and a 500 in a Permanent
 * Secretary's bell because one module shipped a week later than another is a
 * far worse outcome than a row that simply is not a link yet.
 */
final class NotificationLink
{
    /**
     * payload key => record page and register, per surface. Most specific
     * first. A null route means "this surface has no such screen".
     *
     * @var list<array{key: string, tenant: string|null, oversight: string|null, tenantIndex: string|null, oversightIndex: string|null}>
     */
    private const TARGETS = [
        ['key' => 'inspection_ulid', 'tenant' => 'tenant.inspections.show', 'oversight' => null, 'tenantIndex' => 'tenant.inspections.index', 'oversightIndex' => 'oversight.inspections.index'],
        ['key' => 'issue_ulid', 'tenant' => 'tenant.issues.show', 'oversight' => null, 'tenantIndex' => 'tenant.issues.index', 'oversightIndex' => null],
        ['key' => 'exception_ulid', 'tenant' => 'tenant.exceptions.show', 'oversight' => null, 'tenantIndex' => 'tenant.exceptions.index', 'oversightIndex' => 'oversight.exceptions.index'],
        ['key' => 'evaluation_ulid', 'tenant' => 'tenant.evaluations.show', 'oversight' => 'oversight.evaluations.show', 'tenantIndex' => 'tenant.evaluations.index', 'oversightIndex' => 'oversight.evaluations.index'],
        ['key' => 'recommendation_ulid', 'tenant' => 'tenant.recommendations.show', 'oversight' => null, 'tenantIndex' => 'tenant.recommendations.index', 'oversightIndex' => 'oversight.recommendations.index'],
        ['key' => 'workplan_ulid', 'tenant' => 'tenant.workplans.show', 'oversight' => null, 'tenantIndex' => 'tenant.workplans.index', 'oversightIndex' => 'oversight.workplans.index'],
        ['key' => 'certificate_ulid', 'tenant' => 'tenant.certificates.show', 'oversight' => null, 'tenantIndex' => 'tenant.certificates.index', 'oversightIndex' => 'oversight.certificates.index'],
        ['key' => 'report_ulid', 'tenant' => 'tenant.reports.show', 'oversight' => null, 'tenantIndex' => 'tenant.reports.index', 'oversightIndex' => 'oversight.reports.index'],
        // Obligations have no page of their own anywhere: an MDA-level one
        // (no project) lands on the statutory calendar, or the compliance
        // desk on the state surface.
        ['key' => 'obligation_id', 'tenant' => null, 'oversight' => null, 'tenantIndex' => 'tenant.reports.calendar', 'oversightIndex' => 'oversight.compliance.index'],
        ['key' => 'project_ulid', 'tenant' => 'tenant.projects.show', 'oversight' => 'oversight.projects.show', 'tenantIndex' => 'tenant.projects.index', 'oversightIndex' => null],
    ];

    /**
     * The subject's own name, most specific first — what the row is ABOUT.
     * `title` is the issue register's own column.
     */
    private const SUBJECT_TITLES = [
        'activity_title', 'recommendation_title', 'evaluation_title', 'workplan_title', 'title',
    ];

    public static function for(DatabaseNotification $notification, bool $onTenantSurface): ?string
    {
        $data = $notification->data;
        $register = null;

        foreach (self::TARGETS as $target) {
            $identifier = $data[$target['key']] ?? null;

            if ((! is_string($identifier) && ! is_int($identifier)) || $identifier === '') {
                continue;
            }

            $page = self::url($onTenantSurface ? $target['tenant'] : $target['oversight'], [$identifier]);

            if ($page !== null) {
                return $page;
            }

            // Remember only the MOST specific register, but keep looking: a
            // less specific record page (the project) still beats it.
            $register ??= self::url($onTenantSurface ? $target['tenantIndex'] : $target['oversightIndex']);
        }

        return $register;
    }

    /**
     * @param  list<int|string>  $parameters
     */
    private static function url(?string $routeName, array $parameters = []): ?string
    {
        if ($routeName === null || ! Route::has($routeName)) {
            return null;
        }

        try {
            return route($routeName, $parameters);
        } catch (Throwable) {
            // A route whose binding shape differs from a bare public id.
            // Degrade to "no link" rather than break the whole list.
            return null;
        }
    }

    /**
     * A one-line human summary for the row, built from the payload rather than
     * re-rendering the notification: the mail body is written for an inbox and
     * reads badly in a list.
     */
    public static function summary(DatabaseNotification $notification): string
    {
        $data = $notification->data;

        $subject = null;

        foreach (self::SUBJECT_TITLES as $key) {
            if (is_string($data[$key] ?? null) && $data[$key] !== '') {
                $subject = $data[$key];

                break;
            }
        }

        $reference = $data['project_reference'] ?? null;
        $title = $data['project_title'] ?? null;
        $period = $data['period_label'] ?? null;

        $parts = array_values(array_filter([
            is_string($period) ? $period : null,
            $subject,
            is_string($title) ? $title : null,
            is_string($reference) ? '('.$reference.')' : null,
        ]));

        return $parts === [] ? self::headline($notification) : implode(' · ', $parts);
    }

    /** The notification's own kind, humanised — never a raw enum value. */
    public static function headline(DatabaseNotification $notification): string
    {
        $data = $notification->data;
        $type = $data['type'] ?? null;

        if (! is_string($type) || $type === '') {
            return __('Notification');
        }

        return ucfirst(str_replace(['.', '_'], [' — ', ' '], $type));
    }
}
