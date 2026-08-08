<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function person(): BelongsTo
    {
        return $this->belongsTo(ShtabPerson::class, 'person_id');
    }

    public function object(): BelongsTo
    {
        return $this->belongsTo(ShtabObject::class, 'object_id');
    }

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
