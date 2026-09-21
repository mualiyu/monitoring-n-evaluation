<?php

namespace App\Models;

use App\Enums\ChecklistResponseType;
use Database\Factories\InspectionChecklistTemplateItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One question on an inspection instrument — GLOBAL reference data, like its
 * parent, and therefore without BelongsToTenant.
 *
 * @property int $id
 * @property string $ulid
 * @property int $inspection_checklist_template_id
 * @property string $prompt
 * @property string|null $guidance
 * @property ChecklistResponseType $response_type
 * @property bool $finding_on_no
 * @property string|null $finding_threshold
 * @property int|null $rating_scale
 * @property string|null $unit
 * @property bool $is_required
 * @property int $position
 */
#[Fillable([
    'inspection_checklist_template_id', 'prompt', 'guidance', 'response_type',
    'finding_on_no', 'finding_threshold', 'rating_scale', 'unit',
    'is_required', 'position',
])]
class InspectionChecklistTemplateItem extends Model
{
    /** @use HasFactory<InspectionChecklistTemplateItemFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (InspectionChecklistTemplateItem $item): void {
            $item->ulid ??= (string) Str::ulid();
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('inspections')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'response_type' => ChecklistResponseType::class,
            'finding_on_no' => 'boolean',
            'finding_threshold' => 'decimal:2',
            'is_required' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<InspectionChecklistTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(InspectionChecklistTemplate::class, 'inspection_checklist_template_id');
    }

    /**
     * Whether THIS answer is a finding. The single definition, consulted by
     * RecordChecklistResponses when it stores the judgement and by nothing
     * else — a screen that re-derived it would eventually disagree with the
     * stored flag, and the stored flag is what the reports count.
     *
     * Written answers are never auto-judged: an inspector's prose is read by a
     * person, and a keyword match would turn "no defects of any kind" into a
     * failure. The officer flags those by hand.
     */
    public function judge(bool|float|string|null $value): bool
    {
        if (! $this->finding_on_no || $value === null) {
            return false;
        }

        return match ($this->response_type) {
            ChecklistResponseType::YesNo => $value === false,
            ChecklistResponseType::Rating, ChecklistResponseType::Numeric => $this->finding_threshold !== null
                // Integer basis points: a threshold comparison must not be a
                // float comparison, the same rule the money and percentage
                // columns follow.
                && (int) round(((float) $value) * 100) <= (int) round(((float) $this->finding_threshold) * 100),
            ChecklistResponseType::Text => false,
        };
    }
}
