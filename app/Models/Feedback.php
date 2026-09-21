<?php

namespace App\Models;

use App\Enums\FeedbackChannel;
use App\Enums\FeedbackStatus;
use Carbon\CarbonImmutable;
use Database\Factories\FeedbackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Something a member of the public said about a project — GLOBAL, no tenancy
 * column (see the migration for why). Tenancy is carried by `project`, which
 * is tenant-owned and globally scoped, so an MDA's queue narrows itself.
 *
 * Guarded-by-omission, exactly as Project is. `status`, `moderated_*`,
 * `flagged_as_spam`, `spam_reason`, `ip_address` and `user_agent` are NOT
 * fillable:
 *  - the moderation columns belong to App\Actions\Feedback\ModerateFeedback,
 *    the single writer of the state machine, and
 *  - the forensic columns are read off the request by SubmitFeedback, so no
 *    anonymous form payload can forge the IP that a ban would be issued
 *    against, or self-publish by posting `status=published`.
 *
 * @property int $id
 * @property string $ulid
 * @property int|null $project_id
 * @property string $subject
 * @property string $body
 * @property string|null $submitter_name
 * @property string|null $submitter_email
 * @property string|null $submitter_phone
 * @property FeedbackChannel $channel
 * @property FeedbackStatus $status
 * @property int|null $moderated_by_id
 * @property CarbonImmutable|null $moderated_at
 * @property string|null $moderation_reason
 * @property bool $flagged_as_spam
 * @property string|null $spam_reason
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'project_id', 'subject', 'body', 'submitter_name', 'submitter_email',
    'submitter_phone', 'channel',
])]
class Feedback extends Model
{
    /** @use HasFactory<FeedbackFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * "Feedback" is a mass noun; Eloquent would pluralise it to `feedbacks`,
     * which is not a word the DBA reading this schema should have to meet.
     *
     * @var string
     */
    protected $table = 'feedback';

    protected static function booted(): void
    {
        static::creating(function (Feedback $feedback): void {
            $feedback->ulid ??= (string) Str::ulid();
        });
    }

    /**
     * Everything auditable. The moderation columns are the whole point: "who
     * decided this citizen's complaint would not appear on the state website,
     * and on what grounds" is the question this log exists to answer.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('feedback')
            ->logFillable()
            ->logOnly([
                'status', 'moderated_by_id', 'moderated_at', 'moderation_reason',
                'flagged_as_spam', 'spam_reason',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'channel' => FeedbackChannel::class,
            'status' => FeedbackStatus::class,
            'flagged_as_spam' => 'boolean',
            'moderated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * The tenancy anchor. Querying through this relation applies the project's
     * own TenantScope, which is how an MDA's moderation queue confines itself
     * without a single hand-written tenant clause.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function moderatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by_id');
    }

    /** @return HasMany<FeedbackResponse, $this> */
    public function responses(): HasMany
    {
        return $this->hasMany(FeedbackResponse::class)->orderBy('responded_at');
    }

    /**
     * The replies the portal may render — and only ever under a feedback that
     * is itself published (the caller applies that gate; see
     * App\Actions\Portal\ListPublishedFeedback).
     *
     * @return HasMany<FeedbackResponse, $this>
     */
    public function publicResponses(): HasMany
    {
        return $this->responses()->where('is_public', true);
    }

    /**
     * The ONE predicate the portal is allowed to read through. Kept here so
     * "what the public can see" is a single greppable expression rather than a
     * `where` repeated across four screens, one of which will eventually be
     * written without it.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', FeedbackStatus::Published);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAwaitingModeration(Builder $query): Builder
    {
        return $query->where('status', FeedbackStatus::Pending);
    }

    /** A display name for a submitter who may well have given none. */
    public function submitterLabel(): string
    {
        $name = trim((string) $this->submitter_name);

        return $name === '' ? __('Anonymous') : $name;
    }

    public function isPublic(): bool
    {
        return $this->status->isPublic();
    }
}
