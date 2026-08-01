<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Instance-level configuration (branding, terminology, deadline rules…).
 * Global by definition — tenant overrides live in TenantSetting.
 *
 * @property int $id
 * @property string $group
 * @property string $key
 * @property mixed $value
 */
#[Fillable(['group', 'key', 'value'])]
class Setting extends Model
{
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
