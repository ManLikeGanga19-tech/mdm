<?php

namespace App\Filament\Resources\EnrollmentTokens\Pages;

use App\Filament\Resources\EnrollmentTokens\EnrollmentTokenResource;
use App\Mdm\Models\DeviceGroup;
use App\Mdm\Services\EnrollmentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class ManageEnrollmentTokens extends ManageRecords
{
    protected static string $resource = EnrollmentTokenResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('New enrollment code')
                ->icon(Heroicon::OutlinedPlus)
                ->schema([
                    TextInput::make('label')->required()->maxLength(80)->default('Pilot RugKings')
                        ->helperText('For your reference, e.g. "Pilot RugKings, October".'),
                    Select::make('group_id')->label('Put the phones in')
                        ->options(fn () => DeviceGroup::query()->orderBy('name')->pluck('name', 'id'))
                        ->default(fn () => DeviceGroup::query()->value('id')),
                    TextInput::make('max_uses')->label('Number of phones')->numeric()->minValue(1)->maxValue(100)->default(6)->required(),
                    TextInput::make('valid_days')->label('Valid for (days)')->numeric()->minValue(1)->maxValue(30)->default(7)->required(),
                ])
                ->action(function (array $data) {
                    [$code] = app(EnrollmentService::class)->issueCode(
                        $data['label'],
                        filled($data['group_id'] ?? null) ? DeviceGroup::find($data['group_id']) : null,
                        (int) $data['max_uses'],
                        (int) $data['valid_days'],
                    );
                    Notification::make()
                        ->title('Enrollment code created')
                        ->body(new HtmlString('<p style="font-family:ui-monospace,monospace;font-size:1.05em;font-weight:700;user-select:all">'.e($code).'</p>'
                            .'<p>Type it into NoahportEnroll.exe when it asks. Copy it now: it is not shown again.</p>'))
                        ->success()
                        ->persistent()
                        ->send();
                }),
        ];
    }
}
