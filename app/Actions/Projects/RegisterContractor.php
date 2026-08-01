<?php

namespace App\Actions\Projects;

use App\Models\Contractor;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Adds a firm to the STATE's vendor registry — a global write from a tenant
 * surface, and the one place in this module where that is correct
 * (Contractor's docblock explains why the table has no tenant_id).
 *
 * Deduped on `rc_number`: an RC number identifies a legal entity at the CAC,
 * so two rows carrying one would let a firm blacklisted as itself keep winning
 * work as its own duplicate. A hit returns the existing record UNCHANGED —
 * editing a vendor record is oversight authority (`contractors.manage`), and a
 * "register" call must never become an edit of a firm another MDA's contracts
 * depend on.
 *
 * `created_by_tenant_id` records which workspace first registered the firm.
 * That is PROVENANCE, never a scope key.
 */
class RegisterContractor
{
    /**
     * @param  array<string, mixed>  $attributes  name, rc_number, type, contacts…
     */
    public function __invoke(User $actor, array $attributes): Contractor
    {
        Gate::forUser($actor)->authorize('create', Contractor::class);

        $rcNumber = isset($attributes['rc_number'])
            ? Str::upper(trim((string) $attributes['rc_number']))
            : null;

        if ($rcNumber !== null && $rcNumber !== '') {
            $existing = Contractor::query()->where('rc_number', $rcNumber)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return Contractor::create([
            ...$attributes,
            'rc_number' => $rcNumber === '' ? null : $rcNumber,
            'created_by_id' => $actor->id,
            'created_by_tenant_id' => app(CurrentTenant::class)->id(),
            // Blacklisting is a separate, oversight-only act with a mandatory
            // reason — never something a registration payload can assert.
            'is_blacklisted' => false,
            'blacklist_reason' => null,
        ]);
    }
}
