<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon $date
 * @property string $type
 * @property float $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class NutritionMetric extends Model
{
    protected $fillable = [
        'date',
        'type',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'value' => 'float',
        ];
    }
}
