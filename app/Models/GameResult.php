<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $score_you
 * @property int $score_bank
 * @property int $score_max
 * @property int $ratio
 * @property array<int, mixed>|null $choices
 * @property array<string, mixed>|null $survey_answers
 * @property array<string, mixed>|null $composition
 * @property string $promo_code
 */
class GameResult extends Model
{
    protected $fillable = [
        'user_id',
        'game_content_id',
        'score_you',
        'score_bank',
        'score_max',
        'ratio',
        'choices',
        'survey_answers',
        'composition',
        'promo_code',
    ];

    protected function casts(): array
    {
        return [
            'choices' => 'array',
            'survey_answers' => 'array',
            'composition' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
