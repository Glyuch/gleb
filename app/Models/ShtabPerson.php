<?php

namespace App\Models;

use Database\Factories\ShtabPersonFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $initials
 * @property string $class
 * @property string $color
 * @property bool $is_direct
 * @property int|null $manager_id
 * @property bool $is_me
 * @property int $sort
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ShtabPerson extends Model
{
    /** @use HasFactory<ShtabPersonFactory> */
    use HasFactory;

    protected $table = 'shtab_people';

    protected $fillable = [
        'name', 'initials', 'class', 'color', 'is_direct',
        'manager_id', 'is_me', 'sort', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'is_direct' => 'boolean',
            'is_me' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ShtabPerson, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    /**
     * @return HasMany<ShtabAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(ShtabAssignment::class, 'person_id');
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
