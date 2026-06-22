<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $event
 * @property array<string, mixed>|null $payload
 */
class GameEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'game_content_id', 'event', 'payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
