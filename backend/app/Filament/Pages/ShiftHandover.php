<?php

namespace App\Filament\Pages;

use App\Mdm\Models\Clerk;
use App\Mdm\Models\Device;
use App\Mdm\Services\HandoverService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Coordinators record, at each shift handover, which clerk now holds each shared phone. That is what lets usage and
 * network activity be attributed to a clerk later.
 */
class ShiftHandover extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.shift-handover';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?int $navigationSort = 0;

    protected static ?string $title = 'Shift handover';

    public static function canAccess(): bool
    {
        return auth()->user()?->canRecordHandovers() ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Device::query()->where('state', '!=', Device::STATE_RETIRED)->with('currentAssignment.clerk'))
            ->defaultSort('asset_tag')
            ->paginated(false)
            ->columns([
                TextColumn::make('asset_tag')->label('Phone')->weight('bold')->searchable(),
                TextColumn::make('currentAssignment.clerk.name')
                    ->label('Held by')
                    ->placeholder('Nobody')
                    ->description(fn (Device $record) => $record->currentAssignment?->clerk?->staff_number),
                TextColumn::make('currentAssignment.started_at')->label('Since')->since()->placeholder('-'),
            ])
            ->recordActions([
                Action::make('handOver')
                    ->label('Hand over')
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->button()
                    ->modalHeading(fn (Device $record) => "Hand over {$record->asset_tag}")
                    ->modalDescription(fn (Device $record) => $record->currentAssignment
                        ? "Currently held by {$record->currentAssignment->clerk->label()}."
                        : 'Nobody holds this phone now.')
                    ->schema([
                        Select::make('clerk_id')
                            ->label('Clerk taking the phone')
                            ->options(fn () => Clerk::query()->where('active', true)->orderBy('name')->get()
                                ->mapWithKeys(fn (Clerk $c) => [$c->id => $c->label()]))
                            ->searchable()
                            ->required(),
                        TextInput::make('note')
                            ->label('Note (optional)')
                            ->placeholder('e.g. screen cracked, battery 40%')
                            ->maxLength(255),
                    ])
                    ->action(function (array $data, Device $record) {
                        $clerk = Clerk::findOrFail($data['clerk_id']);
                        app(HandoverService::class)->handOver($record, $clerk, $data['note'] ?? null);
                        Notification::make()->title("{$record->asset_tag} is now with {$clerk->name}")->success()->send();
                    }),
                Action::make('return')
                    ->label('Returned')
                    ->icon(Heroicon::OutlinedInboxArrowDown)
                    ->color('gray')
                    ->visible(fn (Device $record) => $record->currentAssignment !== null)
                    ->requiresConfirmation()
                    ->modalHeading(fn (Device $record) => "{$record->asset_tag} returned")
                    ->modalDescription('Record that nobody holds this phone now (e.g. after the last shift).')
                    ->action(function (Device $record) {
                        app(HandoverService::class)->returnPhone($record);
                        Notification::make()->title("{$record->asset_tag} returned")->success()->send();
                    }),
            ]);
    }
}
