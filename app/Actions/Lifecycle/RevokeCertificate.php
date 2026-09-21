<?php

declare(strict_types=1);

namespace App\Actions\Lifecycle;

use App\Exceptions\Lifecycle\LifecycleRuleViolation;
use App\Models\Certificate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Withdraw a completion certificate, on the record.
 *
 * NOT A DELETE. The certificate row, its PDF and its number all survive: a
 * document that was served on a contractor and then withdrawn is precisely
 * what an auditor comes looking for, and the number is never re-used
 * (Certificate::mintReference counts trashed rows for exactly this reason).
 *
 * THE PROJECT STATUS IS NOT REVERTED, deliberately. The lifecycle table
 * (App\Enums\ProjectStatus) gives `certified` one exit — `closed` — so there
 * is no transition back to `completed`, and manufacturing one here would mean
 * a second writer for a column with a designated chokepoint. A certified
 * project whose certificate was withdrawn is handled by issuing a corrected
 * certificate: the register then shows the withdrawal and its replacement,
 * which is the true history. If a state ever needs `certified → completed`,
 * that belongs in the transition table and in TransitionProjectStatus.
 */
class RevokeCertificate
{
    public function __invoke(Certificate $certificate, User $actor, string $reason): Certificate
    {
        Gate::forUser($actor)->authorize('revoke', $certificate);

        $reason = trim($reason);

        // Withdrawing a signed certificate without saying why is an
        // unexplained hole in a project's record.
        if ($reason === '') {
            throw LifecycleRuleViolation::revocationRequiresReason();
        }

        if ($certificate->isRevoked()) {
            throw LifecycleRuleViolation::certificateAlreadyRevoked();
        }

        return DB::transaction(function () use ($certificate, $actor, $reason): Certificate {
            $locked = Certificate::query()->lockForUpdate()->findOrFail($certificate->id);

            if ($locked->isRevoked()) {
                throw LifecycleRuleViolation::certificateAlreadyRevoked();
            }

            // forceFill: the revocation columns are deliberately not fillable
            // — this Action is their only writer.
            $locked->forceFill([
                'revoked_by_id' => $actor->id,
                'revoked_at' => now(),
                'revocation_reason' => $reason,
            ])->save();

            return $locked;
        });
    }
}
