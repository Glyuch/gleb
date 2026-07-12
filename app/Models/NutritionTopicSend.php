<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $profile_id
 * @property int $topic_id
 * @property Carbon|null $scheduled_on
 * @property Carbon|null $sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class NutritionTopicSend extends Model
{
    protected $fillable = [
        'profile_id',
        'topic_id',
        'scheduled_on',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_on' => 'date',
            'sent_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(NutritionProfile::class, 'profile_id');
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(NutritionTopic::class, 'topic_id');
    }
}
