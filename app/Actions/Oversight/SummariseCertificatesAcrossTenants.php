<?php

namespace App\Actions\Oversight;

use App\Enums\CertificateType;
use App\Models\Certificate;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;

/**
 * The four figures above the state completion register: how much the state has
 * certified, of which kinds, and how much is still inside a defects-liability
 * period it can act within.
 *
 * Separate from ListCertificatesAcrossTenants because the stat row must NOT
 * move with the filter bar — it answers "what has the state certified", not
 * "what is on screen", and a summary that follows the search box cannot
 * answer the first question. Same permission, same explicit bypass.
 */
class SummariseCertificatesAcrossTenants
{
    /**
     * @return array{total: int, practical: int, final: int, under_defects_liability: int, revoked: int}
     */
    public function __invoke(User $actor): array
    {
        if (! $actor->holdsGlobalPermission('certificates.view')) {
            throw new AuthorizationException('Viewing certificates across MDAs requires oversight authority.');
        }

        return app(CurrentTenant::class)->bypass(function (): array {
            $now = Carbon::now();

            // One round trip: this is the landing card of the oversight
            // register and it is refreshed all morning.
            $row = Certificate::query()
                ->toBase()
                ->selectRaw('COUNT(CASE WHEN revoked_at IS NULL THEN 1 END) as total')
                ->selectRaw(
                    'COUNT(CASE WHEN revoked_at IS NULL AND type = ? THEN 1 END) as practical',
                    [CertificateType::PracticalCompletion->value],
                )
                ->selectRaw(
                    'COUNT(CASE WHEN revoked_at IS NULL AND type = ? THEN 1 END) as final_completion',
                    [CertificateType::FinalCompletion->value],
                )
                ->selectRaw(
                    'COUNT(CASE WHEN revoked_at IS NULL AND defects_liability_ends_on >= ? THEN 1 END) as under_defects',
                    [$now->toDateString()],
                )
                ->selectRaw('COUNT(CASE WHEN revoked_at IS NOT NULL THEN 1 END) as revoked')
                ->whereNull('deleted_at')
                ->first();

            return [
                'total' => (int) ($row->total ?? 0),
                'practical' => (int) ($row->practical ?? 0),
                'final' => (int) ($row->final_completion ?? 0),
                'under_defects_liability' => (int) ($row->under_defects ?? 0),
                'revoked' => (int) ($row->revoked ?? 0),
            ];
        });
    }
}
