<?php

namespace App\Actions\Evaluation;

use App\Exceptions\Evaluation\EvaluationRuleViolation;
use App\Jobs\Evaluation\NotifyEvaluationTeam;
use App\Models\Evaluation;
use App\Models\EvaluationTeamMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Sets the evaluation team: exactly one lead, any number of members, each
 * either a platform user or a named external evaluator.
 *
 * SYNC, NOT APPEND. The roster is written whole, because the screen edits it
 * whole and because "remove Dr Adeyemi" has to be expressible. Members that
 * survive the sync keep their row (and therefore their id and their audit
 * trail); members that do not are soft-deleted, so who was on the team in
 * March is still answerable in September.
 *
 * THE LEAD IS THE SEPARATION KEY. TransitionEvaluationStatus refuses an
 * approval by the lead, so exactly-one-lead is not cosmetic: zero leads would
 * make the guard vacuous and two would make "the lead" ambiguous. It is
 * enforced here under a row lock rather than by a partial unique index, which
 * MySQL does not have and which soft deletes would defeat.
 */
class AssignEvaluationTeam
{
    /**
     * @param  list<array<string, mixed>>  $members  each: user_id | external_name
     *                                               (+ external_organisation),
     *                                               role (lead|member), expertise
     * @return list<int> ids of the users newly added to the team — the ones
     *                   the commissioning notice goes to
     */
    public function __invoke(Evaluation $evaluation, User $actor, array $members): array
    {
        Gate::forUser($actor)->authorize('update', $evaluation);

        $normalised = $this->normalise($members);

        $newUserIds = DB::transaction(function () use ($evaluation, $normalised): array {
            // The lock is on the evaluation, not on the roster rows: two
            // officers saving two different teams in the same second must
            // serialise, and there is no roster row to lock for a member
            // being added for the first time.
            Evaluation::query()->lockForUpdate()->find($evaluation->id);

            $existing = EvaluationTeamMember::query()
                ->where('evaluation_id', $evaluation->id)
                ->get();

            $existingUserIds = $existing->pluck('user_id')->filter()->all();
            $kept = [];

            foreach ($normalised as $member) {
                $match = $existing->first(fn (EvaluationTeamMember $row): bool => $this->matches($row, $member));

                if ($match !== null) {
                    $match->fill($member)->save();
                    $kept[] = $match->id;

                    continue;
                }

                $created = EvaluationTeamMember::create([
                    ...$member,
                    'evaluation_id' => $evaluation->id,
                ]);

                $kept[] = $created->id;
            }

            foreach ($existing as $row) {
                if (! in_array($row->id, $kept, true)) {
                    $row->delete(); // soft — who was on the team in March stays answerable
                }
            }

            $newUserIds = [];

            foreach ($normalised as $member) {
                $userId = $member['user_id'] ?? null;

                if (is_int($userId) && ! in_array($userId, $existingUserIds, true)) {
                    $newUserIds[] = $userId;
                }
            }

            return $newUserIds;
        });

        // Dispatched after the write closes, never inside it: a notification
        // sent from a transaction that then rolls back has already gone.
        if ($newUserIds !== []) {
            NotifyEvaluationTeam::dispatch($evaluation->id, $newUserIds);
        }

        return $newUserIds;
    }

    /**
     * @param  list<array<string, mixed>>  $members
     * @return list<array<string, mixed>>
     */
    private function normalise(array $members): array
    {
        $normalised = [];
        $leads = 0;
        $seenUsers = [];

        foreach ($members as $member) {
            $userId = $member['user_id'] ?? null;
            $userId = $userId === null || $userId === '' ? null : (int) $userId;
            $externalName = trim((string) ($member['external_name'] ?? ''));

            // A row that names nobody tells a reader nothing and cannot be
            // notified, credited or held to a finding.
            if ($userId === null && $externalName === '') {
                throw EvaluationRuleViolation::memberNeedsAName();
            }

            if ($userId !== null) {
                if (in_array($userId, $seenUsers, true)) {
                    throw EvaluationRuleViolation::duplicateTeamMember();
                }

                $seenUsers[] = $userId;
            }

            $role = $member['role'] ?? EvaluationTeamMember::ROLE_MEMBER;
            $role = in_array($role, EvaluationTeamMember::roles(), true)
                ? $role
                : EvaluationTeamMember::ROLE_MEMBER;

            if ($role === EvaluationTeamMember::ROLE_LEAD) {
                $leads++;
            }

            $normalised[] = [
                'user_id' => $userId,
                'external_name' => $userId === null ? $externalName : null,
                'external_organisation' => $userId === null
                    ? (trim((string) ($member['external_organisation'] ?? '')) ?: null)
                    : null,
                'role' => $role,
                'expertise' => trim((string) ($member['expertise'] ?? '')) ?: null,
            ];
        }

        if ($normalised !== [] && $leads !== 1) {
            throw EvaluationRuleViolation::leadRequired();
        }

        return $normalised;
    }

    /**
     * Whether an existing roster row IS this member. Account holders match on
     * the user; external evaluators match on their name, which is the only
     * identity they have here.
     *
     * @param  array<string, mixed>  $member
     */
    private function matches(EvaluationTeamMember $row, array $member): bool
    {
        if (($member['user_id'] ?? null) !== null) {
            return $row->user_id === $member['user_id'];
        }

        return $row->user_id === null
            && $row->external_name !== null
            && mb_strtolower($row->external_name) === mb_strtolower((string) $member['external_name']);
    }
}
