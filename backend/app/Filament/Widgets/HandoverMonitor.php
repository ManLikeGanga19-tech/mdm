<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\ShiftReport\HandoverColumns;
use App\Filament\Pages\ShiftReportPage;
use App\Mdm\Models\Shift;
use App\Mdm\Services\ShiftReport;
use Filament\Actions\Action;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Dashboard: the handover that opened the current shift, per phone, live (refreshes every 10 s). During the window
 * phones not yet handed over show "Waiting"; after it, "Missing".
 */
class HandoverMonitor extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '10s';

    public function getTableHeading(): string
    {
        $current = Shift::at(now());
        if ($current === null) {
            return 'Shift handover';
        }
        [$shift, $date] = $current;
        [$from, $to] = $shift->handoverWindow($date);
        $tz = config('app.display_timezone');
        $state = now()->lt($to) ? 'in progress' : 'done';

        return sprintf('%s handover, %s–%s (%s)', $shift->name, $from->copy()->setTimezone($tz)->format('H:i'), $to->copy()->setTimezone($tz)->format('H:i'), $state);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading($this->getTableHeading())
            ->poll('10s')
            ->records(function () {
                [$shift, $date] = Shift::at(now()) ?? [null, null];

                return $shift ? app(ShiftReport::class)->build($shift, $date)['handovers']
                    ->mapWithKeys(fn (array $h) => [$h['device']->id => [
                        'asset_tag' => $h['device']->asset_tag, 'status' => $h['status'],
                        'at' => $h['at'], 'by' => $h['by'], 'to' => $h['to'],
                    ]])->all() : [];
            })
            ->paginated(false)
            ->emptyStateHeading('No shifts or enrolled phones yet')
            ->headerActions([
                Action::make('report')->label('Shift report')->link()->url(fn () => ShiftReportPage::getUrl())
                    ->visible(fn () => ShiftReportPage::canAccess()),
            ])
            ->columns(HandoverColumns::make());
    }
}
