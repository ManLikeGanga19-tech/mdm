<?php

namespace App\Filament\Resources\Devices\Widgets;

use App\Mdm\Models\Device;
use App\Mdm\Services\HandoverEnforcement;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Model;

/**
 * Live status at the top of a phone's page, refreshed every 5 seconds. The phone reports changes the moment they
 * happen (charger, network, battery steps), so this stays within a few seconds of the real phone.
 */
class DeviceLiveStatus extends StatsOverviewWidget
{
    public ?Model $record = null;

    protected ?string $pollingInterval = '5s';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        /** @var Device $device */
        $device = $this->record->fresh(['currentAssignment.clerk']);
        $online = $device->last_checkin_at?->gte(now()->subMinutes(20));

        return [
            Stat::make('Battery', $device->battery_percent === null ? '—' : $device->battery_percent.'%')
                ->description($device->battery_charging ? 'Charging' : ($device->battery_charging === false ? 'On battery' : 'Not reported yet'))
                ->descriptionIcon($device->battery_charging ? 'heroicon-m-bolt' : 'heroicon-m-battery-50')
                ->color(match (true) {
                    (bool) $device->battery_charging => 'success',
                    $device->battery_percent !== null && $device->battery_percent <= 15 => 'danger',
                    $device->battery_percent !== null && $device->battery_percent <= 30 => 'warning',
                    default => 'gray',
                }),
            Stat::make('Network', match ($device->network_type) {
                'wifi' => 'Wi-Fi', 'mobile' => 'Mobile data', 'none' => 'Offline', null => '—', default => ucfirst($device->network_type),
            })->descriptionIcon('heroicon-m-signal')->description('Current connection'),
            Stat::make('Last contact', $device->last_checkin_at?->diffForHumans() ?? 'Never')
                ->description($online ? 'Reachable' : 'Not heard from recently')
                ->descriptionIcon($online ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle')
                ->color($online ? 'success' : 'warning'),
            app(HandoverEnforcement::class)->isLocked($device)
                ? Stat::make('Held by', 'Locked')
                    ->description('Not handed over: waiting for the coordinator')
                    ->descriptionIcon('heroicon-m-lock-closed')
                    ->color('danger')
                : Stat::make('Held by', $device->currentAssignment?->clerk?->name ?? 'Nobody')
                    ->description($device->currentAssignment ? 'Since '.$device->currentAssignment->started_at->diffForHumans() : 'From the shift handover')
                    ->descriptionIcon('heroicon-m-user'),
        ];
    }
}
