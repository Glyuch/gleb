<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $profile_id
 * @property string $direction
 * @property string|null $kind
 * @property string|null $content
 * @property int|null $telegram_message_id
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class NutritionMessage extends Model
{
    protected $fillable = [
        'profile_id',
        'direction',
        'kind',
        'content',
        'telegram_message_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'meta' => 'array',
        ];
    }
}
