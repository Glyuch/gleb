<?php

namespace App\Models;

use Database\Factories\ShtabObjectFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $type
 * @property int|null $parent_id
 * @property string $name
 * @property string|null $emoji
 * @property int $focus_level
 * @property string $color
 * @property int $sort
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ShtabObject extends Model
{
    /** @use HasFactory<ShtabObjectFactory> */
    use HasFactory;

    protected $table = 'shtab_objects';

    protected $fillable = [
        'type', 'parent_id', 'name', 'emoji', 'focus_level', 'color', 'sort', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'focus_level' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ShtabObject, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<ShtabObject, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<ShtabMetric, $this>
     */
    public function metrics(): HasMany
    {
        return $this->hasMany(ShtabMetric::class, 'object_id');
    }

    /**
     * @return HasMany<ShtabAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(ShtabAssignment::class, 'object_id');
    }

    /**
     * @return HasMany<ShtabAssignment, $this>
     */
    public function activeAssignments(): HasMany
    {
        return $this->assignments()->whereNull('ended_at');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }
}
