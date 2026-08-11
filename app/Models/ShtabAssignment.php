<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ShtabAssignmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $person_id
 * @property int $object_id
 * @property string $role_label
 * @property string $role_type
 * @property int $load_percent
 * @property string|null $comment
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $ended_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ShtabAssignment extends Model
{
    /** @use HasFactory<ShtabAssignmentFactory> */
    use HasFactory;

    protected $table = 'shtab_assignments';

    protected $fillable = [
        'person_id', 'object_id', 'role_label', 'role_type', 'load_percent', 'comment', 'started_at', 'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'load_percent' => 'integer',
            'started_at' => 'immutable_date',
            'ended_at' => 'immutable_date',
        ];
    }

    /**
     * Ключи типов участия из конфига: owner | lead | helper | watcher.
     *
     * @return array<int, string>
     */
    public static function roleTypes(): array
    {
        /** @var array<string, array{label: string, short: string, default_load: int}> $roles */
        $roles = config('shtab.roles');

        return array_keys($roles);
    }

    public static function defaultLoad(string $roleType): int
    {
        return (int) config("shtab.roles.{$roleType}.default_load", 25);
    }

    public static function roleLabelFor(string $roleType): string
    {
        return (string) config("shtab.roles.{$roleType}.label", $roleType);
    }

    /**
     * @return BelongsTo<ShtabPerson, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(ShtabPerson::class, 'person_id');
    }

    /**
     * @return BelongsTo<ShtabObject, $this>
     */
    public function object(): BelongsTo
    {
        return $this->belongsTo(ShtabObject::class, 'object_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }
}
