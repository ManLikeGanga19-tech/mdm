<?php

namespace App\Mdm\Jobs;

use App\Mdm\Models\AppInventory;
use App\Mdm\Models\IngestBatch;
use App\Mdm\Models\NetworkLog;
use App\Mdm\Models\UsageSample;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Throwable;

/** Turns a stored raw upload into usage samples, network log rows or inventory rows. Safe to run twice. */
class ProcessIngestBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $batchId) {}

    public function handle(): void
    {
        $batch = IngestBatch::find($this->batchId);
        if (! $batch || $batch->processed_at) {
            return;
        }

        try {
            match ($batch->kind) {
                'usage' => $this->usage($batch),
                'network_logs' => $this->networkLogs($batch),
                'inventory' => $this->inventory($batch),
            };
            $batch->update(['processed_at' => now(), 'error' => null]);
        } catch (Throwable $e) {
            $batch->update(['error' => mb_substr($e->getMessage(), 0, 2000)]);
            throw $e;
        }
    }

    /** Hourly samples are final once the hour is over; a re-sent hour replaces the earlier figures. */
    private function usage(IngestBatch $batch): void
    {
        $rows = collect($batch->raw['samples'])->map(fn (array $s) => [
            'device_id' => $batch->device_id,
            'package_name' => $s['package'],
            'network_type' => $s['network'],
            'rx_bytes' => $s['rx'],
            'tx_bytes' => $s['tx'],
            'period_start' => Carbon::parse($s['start'])->utc(),
            'period_end' => Carbon::parse($s['end'])->utc(),
        ]);
        foreach ($rows->chunk(500) as $chunk) {
            UsageSample::upsert($chunk->all(), ['device_id', 'package_name', 'network_type', 'period_start'], ['rx_bytes', 'tx_bytes', 'period_end']);
        }
    }

    private function networkLogs(IngestBatch $batch): void
    {
        $rows = collect($batch->raw['events'])->map(fn (array $e) => [
            'device_id' => $batch->device_id,
            'log_type' => $e['type'],
            'package_name' => $e['package'] ?? null,
            'host' => isset($e['host']) ? strtolower($e['host']) : null,
            'ip' => $e['ip'] ?? null,
            'port' => $e['port'] ?? null,
            'occurred_at' => Carbon::parse($e['at'])->utc(),
        ]);
        foreach ($rows->chunk(500) as $chunk) {
            NetworkLog::insert($chunk->all());
        }
    }

    private function inventory(IngestBatch $batch): void
    {
        $now = now();
        $rows = collect($batch->raw['apps'])->map(fn (array $a) => [
            'device_id' => $batch->device_id,
            'package_name' => $a['package'],
            'label' => $a['label'] ?? null,
            'version_name' => $a['version_name'] ?? null,
            'version_code' => $a['version_code'] ?? null,
            'system' => (bool) ($a['system'] ?? false),
            'last_seen_at' => $now,
        ]);
        foreach ($rows->chunk(500) as $chunk) {
            AppInventory::upsert($chunk->all(), ['device_id', 'package_name'], ['label', 'version_name', 'version_code', 'system', 'last_seen_at']);
        }
        // The upload is the full list: apps not in it were uninstalled.
        AppInventory::query()->where('device_id', $batch->device_id)->where('last_seen_at', '<', $now)->delete();
    }
}
