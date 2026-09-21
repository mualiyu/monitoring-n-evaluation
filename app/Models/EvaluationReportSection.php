<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\EvaluationReportSectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One section of the manual's eleven-section evaluation report (digest §4) —
 * tenant-owned, one row per section.
 *
 * The template that seeds these rows is resolved by
 * App\Actions\Evaluation\ResolveReportTemplate, so a state that wants a
 * twelfth section changes a setting rather than a migration; a report written
 * under an older template keeps the sections it was written with.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $evaluation_id
 * @property string $key
 * @property string $heading
 * @property int $ordinal
 * @property string|null $body
 * @property bool $is_required
 * @property int|null $updated_by_id
 * @property CarbonImmutable|null $drafted_at
 */
#[Fillable(['evaluation_id', 'key', 'heading', 'ordinal', 'body', 'is_required', 'updated_by_id'])]
class EvaluationReportSection extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<EvaluationReportSectionFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('evaluations')
            ->logFillable()
            ->logOnly(['drafted_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'drafted_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Evaluation, $this> */
    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    public function isWritten(): bool
    {
        return trim((string) $this->body) !== '';
    }

    /** Roughly how much has been written — shown on the section builder. */
    public function wordCount(): int
    {
        $body = trim((string) $this->body);

        return $body === '' ? 0 : str_word_count($body);
    }
}
