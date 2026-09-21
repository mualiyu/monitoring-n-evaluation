<?php

declare(strict_types=1);

namespace App\Actions\Oversight;

use App\Actions\Iam\InviteUser;
use App\Enums\Role;
use App\Enums\TenantType;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Opens a new MDA workspace: a subdomain, a permission team and a first
 * administrator, in one transaction.
 *
 * THE SLUG IS THE HARD PART. It becomes a DNS label and a permanent URL, so it
 * is checked here against three separate things, in this order: the platform's
 * own slug pattern (a single DNS label — Laravel's default domain-parameter
 * regex matches dots, so an unvalidated slug is how `evil.works.<domain>`
 * reaches a tenant route), the reserved list (`oversight`, `www`, `api`…
 * would shadow a surface), and every slug ever issued INCLUDING soft-deleted
 * workspaces — a unique index does not see a soft delete, so re-issuing a
 * retired ministry's subdomain would fail at the database with a 500 instead
 * of a sentence a human can read.
 *
 * THE FIRST ADMINISTRATOR goes through App\Actions\Iam\InviteUser — the one
 * invitation path — so the invitation chain, the token hashing, the
 * "one pending invite per (workspace, email)" rule and the mail all behave
 * exactly as they do everywhere else. A second provisioning-only invite path
 * is how those rules start to differ.
 */
class ProvisionTenant
{
    /**
     * @param  array{name: string, short_name?: string|null, slug: string, type: TenantType|string, sector_id?: int|null, contact_name?: string|null, contact_email?: string|null, contact_phone?: string|null}  $attributes
     */
    public function __invoke(User $actor, array $attributes, ?string $administratorEmail = null): Tenant
    {
        if (! $actor->holdsGlobalPermission('tenants.manage')) {
            throw new AuthorizationException('Provisioning a workspace requires state-level tenants.manage authority.');
        }

        $slug = $this->validatedSlug((string) $attributes['slug']);

        $type = $attributes['type'];
        $type = $type instanceof TenantType ? $type : (TenantType::tryFrom((string) $type) ?? TenantType::Ministry);

        return DB::transaction(function () use ($actor, $attributes, $slug, $type, $administratorEmail): Tenant {
            $tenant = Tenant::create([
                'name' => trim((string) $attributes['name']),
                'short_name' => $this->nullableString($attributes['short_name'] ?? null),
                'slug' => $slug,
                'type' => $type,
                'sector_id' => $attributes['sector_id'] ?? null,
                'contact_name' => $this->nullableString($attributes['contact_name'] ?? null),
                'contact_email' => $this->nullableString($attributes['contact_email'] ?? null),
                'contact_phone' => $this->nullableString($attributes['contact_phone'] ?? null),
            ]);

            // Not fillable: onboarding is a stamp the platform makes, not a
            // value a form supplies.
            $tenant->forceFill(['onboarded_at' => now()])->save();

            activity('tenancy')
                ->causedBy($actor)
                ->performedOn($tenant)
                ->withProperties([
                    'attributes' => [
                        'name' => $tenant->name,
                        'slug' => $tenant->slug,
                        'type' => $tenant->type->value,
                        'url' => $tenant->url(),
                    ],
                ])
                ->log('tenant.provisioned');

            if ($administratorEmail !== null && trim($administratorEmail) !== '') {
                (new InviteUser)($actor, $administratorEmail, Role::MdaAdmin, $tenant);
            }

            return $tenant;
        });
    }

    /**
     * @throws InvalidArgumentException with a message a human can act on
     */
    private function validatedSlug(string $slug): string
    {
        $slug = Str::lower(trim($slug));

        if ($slug === '') {
            throw new InvalidArgumentException('A workspace needs a subdomain.');
        }

        $pattern = '/^'.config('platform.tenant_slug_pattern').'$/';

        if (preg_match($pattern, $slug) !== 1) {
            throw new InvalidArgumentException(
                'A subdomain must be a single DNS label: lowercase letters, digits and hyphens, not starting or ending with a hyphen.'
            );
        }

        /** @var list<string> $reserved */
        $reserved = config('platform.reserved_subdomains', []);

        if (in_array($slug, $reserved, true)) {
            throw new InvalidArgumentException("The subdomain [{$slug}] is reserved by the platform.");
        }

        // withTrashed: a unique index cannot see a soft delete, so a retired
        // workspace still owns its subdomain until it is force-deleted.
        $taken = Tenant::withTrashed()->where('slug', $slug)->exists();

        if ($taken) {
            throw new InvalidArgumentException("The subdomain [{$slug}] is already in use.");
        }

        return $slug;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
