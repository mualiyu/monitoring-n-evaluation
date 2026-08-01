<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\TenantSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-MDA configuration overrides. Falls back to Setting (instance level),
 * then config('platform.*').
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $group
 * @property string $key
 * @property mixed $value
 */
#[Fillable(['tenant_id', 'group', 'key', 'value'])]
class TenantSetting extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<TenantSettingFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
