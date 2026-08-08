<?php

namespace App\Models;

use Database\Factories\ShtabMetricFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $object_id
 * @property string $name
 * @property string $status
 * @property string|null $value_text
 * @property int $sort
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ShtabMetric extends Model
{
    /** @use HasFactory<ShtabMetricFactory> */
    use HasFactory;

    protected $table = 'shtab_metrics';

    protected $fillable = ['object_id', 'name', 'status', 'value_text', 'sort'];

    /**
     * @return BelongsTo<ShtabObject, $this>
     */
    public function object(): BelongsTo
    {
        return $this->belongsTo(ShtabObject::class, 'object_id');
    }
}
