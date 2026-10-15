<?php

namespace App\Mdm\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A raw telemetry upload, kept for reprocessing and deduplicated on batch_uuid (CLAUDE.md rule 4). */
#[Fillable(['device_id', 'batch_uuid', 'kind', 'raw', 'received_at', 'processed_at', 'error'])]
class IngestBatch extends Model
{
    public $timestamps = false;

    protected $table = 'mdm_ingest_batches';

    protected function casts(): array
    {
        return ['raw' => 'array', 'received_at' => 'datetime', 'processed_at' => 'datetime'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
