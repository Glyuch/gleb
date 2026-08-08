<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShtabObject extends Model
{
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

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(ShtabMetric::class, 'object_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ShtabAssignment::class, 'object_id');
    }

    public function activeAssignments(): HasMany
    {
        return $this->assignments()->whereNull('ended_at');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }
}
