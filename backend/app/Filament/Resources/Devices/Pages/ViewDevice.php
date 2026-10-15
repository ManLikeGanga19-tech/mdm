<?php

namespace App\Filament\Resources\Devices\Pages;

use App\Filament\Resources\Devices\DeviceResource;
use App\Filament\Resources\Devices\Widgets\DeviceLiveStatus;
use App\Mdm\Models\Command;
use App\Mdm\Models\Device;
use App\Mdm\Services\CommandService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/** One phone: who holds it, its rules and details, and (super admins only) the controls. */
class ViewDevice extends ViewRecord
{
    protected static string $resource = DeviceResource::class;

    protected function getHeaderActions(): array
    {
        $superAdmin = fn () => auth()->user()->isSuperAdmin();

        return [
            Action::make('hotspot')
                ->label('Hotspot')
                ->icon(Heroicon::OutlinedWifi)
                ->visible($superAdmin)
                ->fillForm(fn (Device $record) => ['hotspot' => $record->policy_overrides['hotspot'] ?? 'group'])
                ->schema([
                    Radio::make('hotspot')
                        ->options([
                            'group' => 'Follow the group rule',
                            'blocked' => 'Blocked on this phone',
                            'allowed' => 'Allowed on this phone (monitored)',
                        ])
                        ->required(),
                ])
                ->action(function (array $data, Device $record) {
                    app(CommandService::class)->overridePolicy($record, 'hotspot', $data['hotspot'] === 'group' ? null : $data['hotspot']);
                    Notification::make()->title('Hotspot rule saved. The phone applies it at its next check-in.')->success()->send();
                }),
            $this->commandAction('lock', Heroicon::OutlinedLockClosed, confirm: false),
            ActionGroup::make([
                $this->commandAction('reboot', Heroicon::OutlinedArrowPath, confirm: true),
                $this->commandAction('reset_password', Heroicon::OutlinedKey, confirm: true),
                $this->commandAction('wipe', Heroicon::OutlinedTrash, confirm: true)->color('danger'),
            ])->visible($superAdmin)->label('More')->button()->color('gray'),
            EditAction::make()->color('gray'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [DeviceLiveStatus::class];
    }

    private function commandAction(string $type, Heroicon $icon, bool $confirm): Action
    {
        $descriptions = [
            'reboot' => 'The phone restarts. Anything unsaved on it is lost.',
            'reset_password' => 'The screen lock is removed; the clerk must set a new one.',
            'wipe' => 'Everything on the phone is erased and it returns to factory settings. It must be enrolled again. This cannot be undone.',
        ];

        return Action::make($type)
            ->label(Command::TYPES[$type])
            ->icon($icon)
            ->visible(fn () => auth()->user()->isSuperAdmin())
            ->requiresConfirmation($confirm)
            ->modalDescription($descriptions[$type] ?? null)
            ->action(function (Device $record) use ($type) {
                app(CommandService::class)->send($record, $type);
                Notification::make()
                    ->title(Command::TYPES[$type].' sent to '.$record->asset_tag)
                    ->body('Follow it under Commands below.')
                    ->success()
                    ->send();
            });
    }
}
