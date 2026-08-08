<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShtabPerson extends Model
{
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

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ShtabAssignment::class, 'person_id');
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
