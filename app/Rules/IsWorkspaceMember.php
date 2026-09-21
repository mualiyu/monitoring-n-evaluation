<?php

declare(strict_types=1);

namespace App\Rules;

use App\Actions\Iam\CheckTenantMembership;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * "This user id belongs to somebody who can actually open this workspace."
 *
 * Assigning work to a user who is not a member is worse than not assigning it:
 * they will never see it, and the record says somebody owns it. `exists` on
 * the users table would accept any account on the platform.
 *
 * The membership gate table cannot be tenant-scoped (it DECIDES tenancy), so
 * the only sanctioned reader is app/Actions/Iam — this rule asks through
 * CheckTenantMembership rather than querying the gate itself.
 */
class IsWorkspaceMember implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $user = User::query()->whereKey($value)->first();

        if (! $user instanceof User || ! app(CheckTenantMembership::class)($user)) {
            $fail(__('The selected :attribute is not a member of this workspace.', ['attribute' => $attribute]));
        }
    }
}
