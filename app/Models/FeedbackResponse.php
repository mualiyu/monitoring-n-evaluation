<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\FeedbackResponseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An official reply on a feedback thread — GLOBAL, tenancy inherited from the
 * parent feedback's project (see the migration).
 *
 * `responded_by_id` and `responded_at` are NOT fillable: who answered the
 * public, and when, is stamped by App\Actions\Feedback\RespondToFeedback from
 * the authenticated actor, never taken from a form.
 *
 * @property int $id
 * @property string $ulid
 * @property int $feedback_id
 * @property string $body
 * @property int $responded_by_id
 * @property bool $is_public
 * @property CarbonImmutable $responded_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['feedback_id', 'body', 'is_public'])]
class FeedbackResponse extends Model
{
    /** @use HasFactory<FeedbackResponseFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (FeedbackResponse $response): void {
            $response->ulid ??= (string) Str::ulid();
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('feedback')
            ->logFillable()
            ->logOnly(['responded_by_id', 'responded_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'responded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Feedback, $this> */
    public function feedback(): BelongsTo
    {
        return $this->belongsTo(Feedback::class);
    }

    /** @return BelongsTo<User, $this> */
    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by_id');
    }
}
