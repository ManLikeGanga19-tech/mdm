<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ManageAuditLogs;
use App\Mdm\Models\AuditLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Read-only record of who did what. Nobody can edit or delete entries from the admin site. */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $modelLabel = 'audit entry';

    protected static ?string $navigationLabel = 'Audit log';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 8;

    public static function canViewAny(): bool
    {
        return auth()->user()?->canViewFleet() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('actor.name')->label('Who')->placeholder('System'),
                TextColumn::make('action')->badge(),
                TextColumn::make('data.asset_tag')->label('Phone')->placeholder('-'),
                TextColumn::make('data')
                    ->label('Details')
                    ->formatStateUsing(fn (AuditLog $record) => collect($record->data ?? [])
                        ->except('asset_tag')
                        ->map(fn ($v, $k) => $k.': '.(is_array($v) ? json_encode($v) : ($v ?? '-')))
                        ->join(' · '))
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('action')->options(fn () => AuditLog::query()->distinct()->pluck('action', 'action')->all()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAuditLogs::route('/'),
        ];
    }
}
