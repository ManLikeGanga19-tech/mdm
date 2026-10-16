<?php

namespace App\Console\Commands;

use App\Mdm\Models\AuditLog;
use App\Mdm\Models\Device;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes phone records (e.g. test phones before a rollout) with everything recorded about them: credentials,
 * commands, holders, telemetry, crew handover lines (all cascade with the device). Shows what would go first and
 * deletes only with --force. Nothing else (accounts, roles, crews, roster, clerks, other phones) is touched.
 */
class RemoveDevices extends Command
{
    protected $signature = 'mdm:remove-devices {asset_tags* : asset tags of the phones to remove} {--force : actually delete}';

    protected $description = 'Remove phone records and their history (dry run unless --force)';

    /** Tables that hold a phone's history (all cascade on delete). */
    private const HISTORY = [
        'mdm_device_credentials' => 'credentials',
        'mdm_commands' => 'commands',
        'mdm_device_assignments' => 'holder records',
        'mdm_status_reports' => 'status reports',
        'mdm_usage_samples' => 'usage samples',
        'mdm_network_logs' => 'website log lines',
        'mdm_app_inventory' => 'installed apps',
        'mdm_ingest_batches' => 'raw uploads',
        'mdm_crew_handover_items' => 'crew handover lines',
    ];

    public function handle(): int
    {
        $tags = $this->argument('asset_tags');
        $devices = Device::query()->whereIn('asset_tag', $tags)->orderBy('asset_tag')->get();
        $missing = array_diff($tags, $devices->pluck('asset_tag')->all());
        if ($missing) {
            $this->error('Not found (nothing done): '.implode(', ', $missing));
            $this->line('Phones on record: '.Device::query()->orderBy('asset_tag')->pluck('asset_tag')->implode(', '));

            return self::FAILURE;
        }

        foreach ($devices as $device) {
            $counts = collect(self::HISTORY)->map(fn ($label, $table) => DB::table($table)->where('device_id', $device->id)->count().' '.$label)->implode(', ');
            $this->line("{$device->asset_tag} ({$device->manufacturer} {$device->model}, serial {$device->serial}, {$device->state}): {$counts}");
        }
        $this->line('Kept: accounts, roles, crews, roster, clerks, groups, settings, the audit log and every other phone ('.Device::query()->whereNotIn('id', $devices->modelKeys())->count().').');

        if (! $this->option('force')) {
            $this->warn('Dry run: nothing deleted. Run again with --force to delete the phones above.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($devices) {
            foreach ($devices as $device) {
                AuditLog::record('device.removed', null, ['asset_tag' => $device->asset_tag, 'serial' => $device->serial, 'by' => 'mdm:remove-devices']);
                $device->delete();
            }
        });
        $this->info('Removed: '.$devices->pluck('asset_tag')->implode(', ').'. If such a phone still runs the agent, its check-ins are now refused; factory reset it before reuse.');

        return self::SUCCESS;
    }
}
