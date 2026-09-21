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
     * payload key => [tenant route, oversight route]. Most specific first.
     *
     * @var list<array{key: string, tenant: string|null, oversight: string|null}>
     */
    private const TARGETS = [
        ['key' => 'inspection_ulid', 'tenant' => 'tenant.inspections.show', 'oversight' => null],
        ['key' => 'issue_ulid', 'tenant' => 'tenant.issues.show', 'oversight' => null],
        ['key' => 'exception_ulid', 'tenant' => 'tenant.exceptions.show', 'oversight' => null],
        ['key' => 'evaluation_ulid', 'tenant' => 'tenant.evaluations.show', 'oversight' => 'oversight.evaluations.show'],
        ['key' => 'recommendation_ulid', 'tenant' => 'tenant.recommendations.show', 'oversight' => null],
        ['key' => 'workplan_ulid', 'tenant' => 'tenant.workplans.show', 'oversight' => null],
        ['key' => 'certificate_ulid', 'tenant' => 'tenant.certificates.show', 'oversight' => null],
        ['key' => 'report_ulid', 'tenant' => 'tenant.reports.show', 'oversight' => null],
        ['key' => 'project_ulid', 'tenant' => 'tenant.projects.show', 'oversight' => 'oversight.projects.show'],
    ];

    public static function for(DatabaseNotification $notification, bool $onTenantSurface): ?string
    {
        $data = is_array($notification->data) ? $notification->data : [];

        foreach (self::TARGETS as $target) {
            $identifier = $data[$target['key']] ?? null;

            if (! is_string($identifier) || $identifier === '') {
                continue;
            }

            $routeName = $onTenantSurface ? $target['tenant'] : $target['oversight'];

            if ($routeName === null || ! Route::has($routeName)) {
                continue;
            }

            try {
                return route($routeName, $identifier);
            } catch (Throwable) {
                // A route whose binding shape differs from a bare public id.
                // Degrade to "no link" rather than break the whole list.
                continue;
            }
        }

        return null;
    }

    /**
     * A one-line human summary for the row, built from the payload rather than
     * re-rendering the notification: the mail body is written for an inbox and
     * reads badly in a list.
     */
    public static function summary(DatabaseNotification $notification): string
    {
        $data = is_array($notification->data) ? $notification->data : [];

        $reference = $data['project_reference'] ?? null;
        $title = $data['project_title'] ?? null;
        $period = $data['period_label'] ?? null;

        $parts = array_values(array_filter([
            is_string($period) ? $period : null,
            is_string($title) ? $title : null,
            is_string($reference) ? '('.$reference.')' : null,
        ]));

        return $parts === [] ? self::headline($notification) : implode(' · ', $parts);
    }

    /** The notification's own kind, humanised — never a raw enum value. */
    public static function headline(DatabaseNotification $notification): string
    {
        $data = is_array($notification->data) ? $notification->data : [];
        $type = $data['type'] ?? null;

        if (! is_string($type) || $type === '') {
            return __('Notification');
        }

        return ucfirst(str_replace(['.', '_'], [' — ', ' '], $type));
    }
}
