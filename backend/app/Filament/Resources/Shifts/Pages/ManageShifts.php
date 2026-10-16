<?php

namespace App\Filament\Resources\Shifts\Pages;

use App\Filament\Resources\Shifts\ShiftResource;
use App\Mdm\Models\AuditLog;
use App\Mdm\Models\Setting;
use App\Mdm\Permission;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageShifts extends ManageRecords
{
    protected static string $resource = ShiftResource::class;

    public function getSubheading(): ?string
    {
        $peaks = collect(Setting::get('peak_hours'))->map(fn ($r) => "{$r[0]}–{$r[1]}")->join(', ');

        return "Peak hours: {$peaks}. Reports split each shift's data into peak and off-peak.";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('peakHours')
                ->label('Peak hours')
                ->icon(Heroicon::OutlinedChartBar)
                ->color('gray')
                ->visible(fn () => auth()->user()->hasPermission(Permission::SetupManage))
                ->fillForm(fn () => ['ranges' => collect(Setting::get('peak_hours'))->map(fn ($r) => ['from' => $r[0], 'to' => $r[1]])->all()])
                ->schema([
                    Repeater::make('ranges')
                        ->label('Busy periods')
                        ->schema([
                            TimePicker::make('from')->seconds(false)->timezone('UTC')->required(), // wall-clock time: no time-zone conversion
                            TimePicker::make('to')->seconds(false)->timezone('UTC')->required()->after('from'),
                        ])
                        ->columns(2)
                        ->minItems(1)
                        ->defaultItems(1),
                ])
                ->action(function (array $data) {
                    $ranges = collect($data['ranges'])->map(fn ($r) => [substr($r['from'], 0, 5), substr($r['to'], 0, 5)])->sortBy(0)->values()->all();
                    Setting::put('peak_hours', $ranges);
                    AuditLog::record('settings.peak_hours', null, ['peak_hours' => $ranges]);
                    Notification::make()->title('Peak hours saved')->success()->send();
                }),
            CreateAction::make()->label('Add shift'),
        ];
    }
}
