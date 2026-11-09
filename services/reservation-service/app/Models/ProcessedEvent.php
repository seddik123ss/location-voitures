<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $event_id
 * @property string $type
 * @property string $queue
 * @property string $outcome
 * @property \Illuminate\Support\Carbon $processed_at
 */
class ProcessedEvent extends Model
{
    protected $primaryKey = 'event_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['event_id', 'type', 'queue', 'outcome', 'processed_at'];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }
}
