<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $profile_id
 * @property Carbon $date
 * @property string $type
 * @property Carbon|null $window_start
 * @property Carbon|null $window_end
 * @property Carbon|null $eaten_at
 * @property string|null $photo_file_id
 * @property string|null $ai_feedback
 * @property int|null $score
 * @property array<string, mixed>|null $rating
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class NutritionMeal extends Model
{
    protected $fillable = [
        'profile_id',
        'date',
        'type',
        'window_start',
        'window_end',
        'eaten_at',
        'photo_file_id',
        'ai_feedback',
        'score',
        'rating',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'date' => 'date',
            'window_start' => 'datetime',
            'window_end' => 'datetime',
            'eaten_at' => 'datetime',
            'score' => 'integer',
            'rating' => 'array',
        ];
    }
}
