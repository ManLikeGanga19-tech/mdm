<?php

namespace App\Filament\Resources\Devices\Widgets;

use App\Mdm\Services\LiveView;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

/**
 * Live console: a terminal-style feed under the live view chart. While live view runs, a line every ~2 s with the
 * phone's speed, network, signal and the apps using the most data, mixed with the phone's events and what admins do
 * to it. Refreshes every 2 s during a session; the last lines stay readable for 15 minutes after it ends.
 */
class DeviceLiveConsole extends Widget
{
    public ?Model $record = null;

    protected string $view = 'filament.widgets.device-live-console';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $live = app(LiveView::class);

        return [
            'active' => $live->isActive($this->record),
            'lines' => $live->lines($this->record),
            'tz' => config('app.display_timezone'),
        ];
    }
}
