<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $type
 * @property int|null $person_id
 * @property int|null $object_id
 * @property int|null $metric_id
 * @property array<string, mixed>|null $payload
 * @property string|null $comment
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ShtabEvent extends Model
{
    protected $table = 'shtab_events';

    protected $fillable = ['type', 'person_id', 'object_id', 'metric_id', 'payload', 'comment'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
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
     * @return BelongsTo<ShtabMetric, $this>
     */
    public function metric(): BelongsTo
    {
        return $this->belongsTo(ShtabMetric::class, 'metric_id');
    }

    /**
     * Единая точка записи Хроники. Вызывается из контроллеров внутри транзакции мутации.
     *
     * @param  array{person_id?: int|null, object_id?: int|null, metric_id?: int|null, payload?: array<string, mixed>|null, comment?: string|null}  $attrs
     */
    public static function record(string $type, array $attrs = []): self
    {
        return self::create(array_merge(['type' => $type], $attrs));
    }
}
