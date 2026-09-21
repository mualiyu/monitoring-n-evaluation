<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\EvaluationTeamMemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A named member of an evaluation team — tenant-owned.
 *
 * Either a platform user (`user_id`) or an external evaluator recorded by
 * name (`external_name` + `external_organisation`). Most real evaluation teams
 * are contracted firms and academics with no account here, and a roster that
 * could only name account-holders would be a roster of the wrong people.
 *
 * `role` is a two-value string rather than an enum because the module's enum
 * surface is deliberately small and this value is never reasoned about outside
 * the two constants below and AssignEvaluationTeam.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $evaluation_id
 * @property int|null $user_id
 * @property string|null $external_name
 * @property string|null $external_organisation
 * @property string $role
 * @property string|null $expertise
 */
#[Fillable([
    'evaluation_id', 'user_id', 'external_name', 'external_organisation',
    'role', 'expertise',
])]
class EvaluationTeamMember extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<EvaluationTeamMemberFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    public const ROLE_LEAD = 'lead';

    public const ROLE_MEMBER = 'member';

    /** @return list<string> */
    public static function roles(): array
    {
        return [self::ROLE_LEAD, self::ROLE_MEMBER];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('evaluations')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /** @return BelongsTo<Evaluation, $this> */
    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isLead(): bool
    {
        return $this->role === self::ROLE_LEAD;
    }

    /**
     * How this member is named on the roster and in the report: their account
     * name when they have one, the typed name otherwise.
     */
    public function displayName(): string
    {
        if ($this->user_id !== null && $this->relationLoaded('user') && $this->user !== null) {
            return $this->user->name;
        }

        return $this->external_name ?? __('Unnamed member');
    }

    public function affiliation(): ?string
    {
        return $this->user_id !== null ? null : $this->external_organisation;
    }

    /**
     * Members that are platform users — the only ones a notification can
     * reach and the only ones a separation guard can match an actor against.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAccountHolders(Builder $query): Builder
    {
        return $query->whereNotNull('user_id');
    }
}
