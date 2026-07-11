<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $event_key
 * @property Carbon $sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class NutritionSentEvent extends Model
{
    protected $fillable = ['event_key', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    /**
     * Выполнить $fn ровно один раз на event_key (unique-индекс защищает от гонок).
     */
    public static function once(string $key, \Closure $fn): bool
    {
        try {
            static::create(['event_key' => $key, 'sent_at' => Carbon::now()]);
        } catch (QueryException) {
            return false; // уже выполнялось
        }

        $fn();

        return true;
    }
}
