<?php

namespace App\Filament\Resources\WorkRules;

use App\Filament\Resources\WorkRules\Pages\ManageWorkRules;
use App\Mdm\Models\AuditLog;
use App\Mdm\Models\WorkRule;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** What counts as work in usage reports: websites (incl. the company PWA in Chrome) and apps. */
class WorkRuleResource extends Resource
{
    protected static ?string $model = WorkRule::class;

    protected static ?string $modelLabel = 'work site or app';

    protected static ?string $navigationLabel = 'Work sites & apps';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return auth()->user()?->canViewFleet() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Radio::make('kind')->options(['domain' => 'Website', 'app' => 'App'])->default('domain')->inline()->live()->required(),
            TextInput::make('pattern')
                ->label(fn (Get $get) => $get('kind') === 'app' ? 'App package name' : 'Web address')
                ->placeholder(fn (Get $get) => $get('kind') === 'app' ? 'com.safaricom.mpesa.lifestyle' : 'app.noahport.co.ke')
                ->helperText(fn (Get $get) => $get('kind') === 'app'
                    ? 'All of this app\'s data counts as work. Find package names on a phone\'s Apps tab.'
                    : 'Includes its subdomains. The company PWA runs in Chrome: enter its address here.')
                ->required()
                ->maxLength(255),
            TextInput::make('label')->placeholder('Company PWA')->required()->maxLength(80),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('label')
            ->emptyStateHeading('No work sites or apps yet')
            ->emptyStateDescription('Add the company PWA\'s web address first, so reports can tell work from other use.')
            ->columns([
                TextColumn::make('label')->weight('bold'),
                TextColumn::make('kind')->badge()->formatStateUsing(fn (string $state) => $state === 'app' ? 'App' : 'Website'),
                TextColumn::make('pattern')->label('Address / package')->fontFamily('mono'),
            ])
            ->recordActions([
                EditAction::make()->after(fn (WorkRule $record) => AuditLog::record('work_rule.updated', $record, $record->only('kind', 'pattern', 'label'))),
                DeleteAction::make()->after(fn (WorkRule $record) => AuditLog::record('work_rule.deleted', $record, $record->only('kind', 'pattern', 'label'))),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageWorkRules::route('/'),
        ];
    }
}
