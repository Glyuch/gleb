<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ShtabTaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $object_id
 * @property string $title
 * @property bool $is_done
 * @property CarbonImmutable|null $done_at
 * @property int|null $assignee_person_id
 * @property bool $is_key
 * @property int $sort
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ShtabTask extends Model
{
    /** @use HasFactory<ShtabTaskFactory> */
    use HasFactory;

    protected $table = 'shtab_tasks';

    protected $fillable = [
        'object_id', 'title', 'is_done', 'done_at', 'assignee_person_id', 'is_key', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'is_done' => 'boolean',
            'is_key' => 'boolean',
            'done_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ShtabObject, $this>
     */
    public function object(): BelongsTo
    {
        return $this->belongsTo(ShtabObject::class, 'object_id');
    }

    /**
     * @return BelongsTo<ShtabPerson, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(ShtabPerson::class, 'assignee_person_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('is_done', false);
    }
}
