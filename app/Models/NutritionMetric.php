<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $profile_id
 * @property Carbon $date
 * @property string $type
 * @property float $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class NutritionMetric extends Model
{
    protected $fillable = [
        'profile_id',
        'date',
        'type',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'date' => 'date',
            'value' => 'float',
        ];
    }
}
