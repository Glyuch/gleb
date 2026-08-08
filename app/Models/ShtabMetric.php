<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShtabMetric extends Model
{
    use HasFactory;

    protected $table = 'shtab_metrics';

    protected $fillable = ['object_id', 'name', 'status', 'value_text', 'sort'];

    public function object(): BelongsTo
    {
        return $this->belongsTo(ShtabObject::class, 'object_id');
    }
}
