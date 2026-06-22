<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property array<string, mixed> $data
 * @property bool $is_active
 */
class GameContent extends Model
{
    protected $fillable = ['data', 'is_active'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<GameContent>  $query
     * @return Builder<GameContent>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->latest('id');
    }

    /**
     * The currently active content version (or null if none seeded yet).
     */
    public static function current(): ?self
    {
        return static::query()->active()->first();
    }

    /**
     * Save a new active version and deactivate the previous ones.
     *
     * @param  array<string, mixed>  $data
     */
    public static function publish(array $data): self
    {
        static::query()->where('is_active', true)->update(['is_active' => false]);

        return static::query()->create(['data' => $data, 'is_active' => true]);
    }
}
