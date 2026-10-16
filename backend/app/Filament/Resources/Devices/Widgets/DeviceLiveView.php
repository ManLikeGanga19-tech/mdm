<?php

namespace App\Filament\Resources\Devices\Widgets;

use App\Mdm\Services\LiveView;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Live view panel: download/upload speed over the last ~2 minutes, refreshed every 2 s, with the current speed,
 * network and signal in the heading. Shows a hint when no live session is running.
 */
class DeviceLiveView extends ChartWidget
{
    public ?Model $record = null;

    protected ?string $pollingInterval = '2s';

    protected ?string $maxHeight = '220px';

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): ?string
    {
        $live = app(LiveView::class);
        $latest = collect($live->samples($this->record))->last();
        if (! $live->isActive($this->record)) {
            return $latest ? 'Live view (ended)' : 'Live view';
        }
        if (! $latest || Carbon::parse($latest['at'])->lt(now()->subSeconds(15))) {
            return 'Live view: waiting for the phone…';
        }

        return sprintf('Live: ↓ %s  ↑ %s', $this->mbps($latest['rx_bps']), $this->mbps($latest['tx_bps']));
    }

    public function getDescription(): ?string
    {
        $live = app(LiveView::class);
        $latest = collect($live->samples($this->record))->last();
        if (! $live->isActive($this->record) && ! $latest) {
            return 'Press Live view to watch this phone\'s connection speed, network and signal for up to 10 minutes.';
        }
        if (! $latest) {
            return 'The phone starts sending within a few seconds.';
        }
        $network = match ($latest['network'] ?? null) {
            'wifi' => 'Wi-Fi', 'mobile' => 'Mobile data', 'none' => 'Offline', default => 'Unknown network',
        };
        $signal = isset($latest['signal_dbm']) ? " · signal {$latest['signal_dbm']} dBm (".$this->bars($latest['signal_dbm']).')' : '';
        $capacity = isset($latest['down_kbps']) ? ' · network estimate '.round($latest['down_kbps'] / 1000, 1).' Mbps' : '';
        $left = $live->remaining($this->record);

        return $network.$signal.$capacity.($left ? ' · '.ceil($left / 60).' min left' : '');
    }

    protected function getData(): array
    {
        $tz = config('app.display_timezone');
        $samples = collect(app(LiveView::class)->samples($this->record))->slice(-60)->values();

        return [
            'datasets' => [
                ['label' => 'Download (Mbps)', 'data' => $samples->map(fn ($s) => round($s['rx_bps'] / 1e6, 2))->all(),
                    'borderColor' => '#2563eb', 'backgroundColor' => 'rgba(37,99,235,0.1)', 'fill' => true, 'tension' => 0.3, 'pointRadius' => 0],
                ['label' => 'Upload (Mbps)', 'data' => $samples->map(fn ($s) => round($s['tx_bps'] / 1e6, 2))->all(),
                    'borderColor' => '#f59e0b', 'tension' => 0.3, 'pointRadius' => 0],
            ],
            'labels' => $samples->map(fn ($s) => Carbon::parse($s['at'])->setTimezone($tz)->format('H:i:s'))->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'animation' => false,
            'scales' => ['y' => ['beginAtZero' => true]],
            'plugins' => ['legend' => ['position' => 'bottom']],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    private function mbps(int $bps): string
    {
        return $bps >= 1e6 ? round($bps / 1e6, 1).' Mbps' : round($bps / 1e3).' kbps';
    }

    /** Rough signal quality from dBm (Wi-Fi and LTE use similar ranges). */
    private function bars(int $dbm): string
    {
        return match (true) {
            $dbm >= -65 => 'excellent',
            $dbm >= -80 => 'good',
            $dbm >= -95 => 'fair',
            default => 'weak',
        };
    }
}
