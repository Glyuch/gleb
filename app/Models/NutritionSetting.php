<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $key
 * @property mixed $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class NutritionSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
