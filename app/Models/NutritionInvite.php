<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property int $created_by_profile_id
 * @property int|null $used_by_profile_id
 * @property Carbon|null $used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class NutritionInvite extends Model
{
    /** Читаемый алфавит: A-Z0-9 без визуально неоднозначных 0/O/1/I. */
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    protected $fillable = [
        'code',
        'created_by_profile_id',
        'used_by_profile_id',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(NutritionProfile::class, 'created_by_profile_id');
    }

    public function usedBy(): BelongsTo
    {
        return $this->belongsTo(NutritionProfile::class, 'used_by_profile_id');
    }

    /** Создаёт инвайт с гарантированно уникальным читаемым кодом. */
    public static function generate(NutritionProfile $by): self
    {
        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (static::query()->where('code', $code)->exists());

        return static::query()->create([
            'code' => $code,
            'created_by_profile_id' => $by->id,
        ]);
    }
}
