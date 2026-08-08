<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShtabAssignment extends Model
{
    use HasFactory;

    protected $table = 'shtab_assignments';

    protected $fillable = [
        'person_id', 'object_id', 'role_label', 'comment', 'started_at', 'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_date',
            'ended_at' => 'immutable_date',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(ShtabPerson::class, 'person_id');
    }

    public function object(): BelongsTo
    {
        return $this->belongsTo(ShtabObject::class, 'object_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }
}
