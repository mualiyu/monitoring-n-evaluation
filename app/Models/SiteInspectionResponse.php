<?php

namespace App\Models;

use App\Enums\ChecklistResponseType;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\SiteInspectionResponseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One answered checklist item — tenant-owned.
 *
 * `prompt` and `response_type` are SNAPSHOTS of the global template item as it
 * read on the day of the visit. The template is curated by the state and may
 * be reworded; a report printed years later must show the question that was
 * actually put to the inspector. `is_finding` is snapshotted for the same
 * reason — it is judged once, against the threshold in force at the time, and
 * never re-derived, so a retuned policy cannot rewrite history.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $site_inspection_id
 * @property int $inspection_checklist_template_item_id
 * @property string $prompt
 * @property ChecklistResponseType $response_type
 * @property bool|null $value_boolean
 * @property string|null $value_number
 * @property string|null $value_text
 * @property bool $is_finding
 * @property string|null $note
 */
#[Fillable([
    'site_inspection_id', 'inspection_checklist_template_item_id', 'prompt',
    'response_type', 'value_boolean', 'value_number', 'value_text',
    'is_finding', 'note',
])]
class SiteInspectionResponse extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SiteInspectionResponseFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('inspections')
            ->logFillable()
            ->useAttributeRawValues(['value_number'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'response_type' => ChecklistResponseType::class,
            'value_boolean' => 'boolean',
            'value_number' => 'decimal:2',
            'is_finding' => 'boolean',
        ];
    }

    /** @return BelongsTo<SiteInspection, $this> */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(SiteInspection::class, 'site_inspection_id');
    }

    /** @return BelongsTo<InspectionChecklistTemplateItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(
            InspectionChecklistTemplateItem::class,
            'inspection_checklist_template_item_id',
        );
    }

    /**
     * The answer, whatever shape it took. One accessor so a screen, an export
     * and a PDF never disagree about how a rating is rendered.
     */
    public function answer(): bool|string|null
    {
        return match ($this->response_type) {
            ChecklistResponseType::YesNo => $this->value_boolean,
            ChecklistResponseType::Rating, ChecklistResponseType::Numeric => $this->value_number,
            ChecklistResponseType::Text => $this->value_text,
        };
    }

    public function isAnswered(): bool
    {
        return $this->answer() !== null && $this->answer() !== '';
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFindings(Builder $query): Builder
    {
        return $query->where('is_finding', true);
    }
}
