<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $title
 * @property string|null $file_path
 * @property string|null $intro
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class NutritionTopic extends Model
{
    protected $fillable = [
        'title',
        'file_path',
        'intro',
        'position',
    ];
}
