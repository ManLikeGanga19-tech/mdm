<?php

namespace App\Filament\Resources\EnrollmentTokens;

use App\Filament\Resources\EnrollmentTokens\Pages\ManageEnrollmentTokens;
use App\Mdm\Models\AuditLog;
use App\Mdm\Models\EnrollmentToken;
use App\Mdm\Permission;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Codes typed into NoahportEnroll.exe to admit phones. The code itself is shown once, at creation. */
class EnrollmentTokenResource extends Resource
{
    protected static ?string $model = EnrollmentToken::class;

    protected static ?string $modelLabel = 'enrollment code';

    protected static ?string $navigationLabel = 'Enrollment codes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static ?int $navigationSort = 4;

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasPermission(Permission::EnrollmentManage) ?? false;
    }

    public static function status(EnrollmentToken $token): string
    {
        return match (true) {
            $token->expires_at->isPast() => 'Expired',
            $token->uses >= $token->max_uses => 'Used up',
            default => 'Active',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No enrollment codes yet')
            ->emptyStateDescription('Create one, then type it into NoahportEnroll.exe on the laptop.')
            ->columns([
                TextColumn::make('label')->weight('bold'),
                TextColumn::make('group.name')->label('Puts phones in')->placeholder('No group'),
                TextColumn::make('uses')->label('Used')->formatStateUsing(fn (EnrollmentToken $record) => "{$record->uses} of {$record->max_uses}"),
                TextColumn::make('expires_at')->label('Expires')->dateTime()->sortable(),
                TextColumn::make('status')
                    ->state(fn (EnrollmentToken $record) => self::status($record))
                    ->badge()
                    ->color(fn (string $state) => $state === 'Active' ? 'success' : 'gray'),
                TextColumn::make('created_at')->label('Created')->since()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('disable')
                    ->label('Disable')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Phones already enrolled stay enrolled. The code stops working immediately.')
                    ->visible(fn (EnrollmentToken $record) => self::status($record) === 'Active')
                    ->action(function (EnrollmentToken $record) {
                        $record->update(['expires_at' => now()]);
                        AuditLog::record('enrollment_code.disabled', $record, ['label' => $record->label]);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageEnrollmentTokens::route('/'),
        ];
    }
}
