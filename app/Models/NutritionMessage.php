<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
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
        'direction',
        'kind',
        'content',
        'telegram_message_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }
}
