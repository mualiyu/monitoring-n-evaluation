<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\EvaluationCriterionScoreFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One criterion of an evaluation's scorecard — tenant-owned, one ROW per
 * criterion rather than one column.
 *
 * The criteria come from `platform.evaluation.criteria` (the OECD-DAC five by
 * default) through App\Support\SettingsRepository, so a state that adds its
 * own criterion changes a setting instead of waiting for a release. That is
 * only possible because the scores are rows.
 *
 * `score` is nullable on purpose: a row exists from commissioning so the
 * scorecard can show what still has to be answered, and null means "not yet
 * scored", which is not the same as zero.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $evaluation_id
 * @property string $criterion
 * @property string|null $score
 * @property string $weight
 * @property string|null $justification
 * @property string|null $evidence_reference
 * @property int|null $scored_by_id
 * @property CarbonImmutable|null $scored_at
 */
#[Fillable([
    'evaluation_id', 'criterion', 'score', 'weight', 'justification',
    'evidence_reference', 'scored_by_id',
])]
class EvaluationCriterionScore extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<EvaluationCriterionScoreFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('evaluations')
            ->logFillable()
            ->logOnly(['scored_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'weight' => 'decimal:2',
            'scored_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Evaluation, $this> */
    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function scoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scored_by_id');
    }

    public function isScored(): bool
    {
        return $this->score !== null;
    }

    /**
     * The criterion's display name. Configured criteria are keys
     * ("gender_responsiveness"); a state that wants different wording supplies
     * it through the setting, and this humanises whatever arrives rather than
     * hard-coding the DAC five in a view.
     */
    public function label(): string
    {
        return Str::headline($this->criterion);
    }

    /** This score as a percentage of the instance's maximum — for the bar. */
    public function percentOf(int $scoreMax): ?float
    {
        if ($this->score === null || $scoreMax <= 0) {
            return null;
        }

        return round(((float) $this->score) * 100 / $scoreMax, 1);
    }
}
