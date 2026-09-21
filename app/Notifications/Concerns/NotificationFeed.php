<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The one query behind the bell and the notification centre.
 *
 * WHY NOTIFICATIONS ARE NOT TENANT-OWNED. The table deliberately carries no
 * tenant_id (see its migration): a notification belongs to a PERSON, and the
 * same person may hold memberships in several MDAs — a consultant working for
 * three ministries has one inbox, not three identities. Scoping the table
 * would mean a notification with no home the moment an oversight officer is
 * told something.
 *
 * WHAT SCOPES IT INSTEAD. On a workspace surface the feed is narrowed to the
 * workspace the request is on, through the `tenant_id` the notification's own
 * payload carries. So the consultant above sees the Works items on Works's
 * subdomain and the Health items on Health's, out of the same inbox, and a
 * notification with no workspace (an invitation, an account change) follows
 * them everywhere because it belongs to them rather than to a ministry.
 *
 * This is a filter on a JSON payload, not a tenancy boundary, and it is not
 * pretending to be one: the AUTHORITY check is on the record each notification
 * links to, which loads through its own global scope and its own policy.
 */
final class NotificationFeed
{
    /**
     * @return Builder<DatabaseNotification>
     */
    public static function for(User $user, ?Tenant $tenant, bool $unreadOnly = false): Builder
    {
        /** @var Builder<DatabaseNotification> $query */
        $query = $user->notifications()->getQuery();

        return $query
            ->when($tenant instanceof Tenant, function (Builder $scoped) use ($tenant): void {
                $scoped->where(function (Builder $match) use ($tenant): void {
                    // Payload filter, not a tenant scope — see the class note.
                    $match->where('data->tenant_id', $tenant?->id)
                        ->orWhereNull('data->tenant_id');
                });
            })
            ->when($unreadOnly, fn (Builder $scoped) => $scoped->whereNull('read_at'))
            ->latest('created_at');
    }

    public static function unreadCount(User $user, ?Tenant $tenant): int
    {
        return self::for($user, $tenant, true)->count();
    }
}
