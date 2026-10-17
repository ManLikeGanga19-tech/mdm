<?php

namespace App\Filament\Resources\AlertRules;

use App\Filament\Resources\AlertRules\Pages\ManageAlertRules;
use App\Mdm\AlertMetric;
use App\Mdm\Models\AdminRole;
use App\Mdm\Models\AlertRule;
use App\Mdm\Models\AuditLog;
use App\Mdm\Models\DeviceGroup;
use App\Mdm\Permission;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

/** What gets emailed, to whom. Checked every 5 minutes by AlertService; the suggested rules start switched off. */
class AlertRuleResource extends Resource
{
    protected static ?string $model = AlertRule::class;

    protected static ?string $modelLabel = 'alert rule';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?int $navigationSort = 7;

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasPermission(Permission::AlertsManage) ?? false;
    }

    private static function metric(Get $get): ?AlertMetric
    {
        $value = $get('metric');

        return $value instanceof AlertMetric ? $value : AlertMetric::tryFrom((string) $value);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(120),
            Select::make('metric')->label('When')->options(AlertMetric::options())->required()->live()
                ->helperText(fn (Get $get) => self::metric($get)?->help()),
            TextInput::make('threshold')->numeric()->integer()->minValue(0)->required()
                ->suffix(fn (Get $get) => self::metric($get)?->unit()),
            Select::make('window')->label('Per')->options(['day' => 'Day', 'week' => 'Week (from Monday)'])->default('day')
                ->visible(fn (Get $get) => self::metric($get)?->usesWindow() ?? false)
                ->required(fn (Get $get) => self::metric($get)?->usesWindow() ?? false),
            Select::make('group_id')->label('Phones')->options(fn () => DeviceGroup::query()->orderBy('name')->pluck('name', 'id'))
                ->placeholder('All phones')
                ->visible(fn (Get $get) => self::metric($get) !== AlertMetric::HandoverMissed),
            Select::make('recipient_role_ids')->label('Send to roles')->multiple()
                ->options(fn () => AdminRole::query()->orderBy('id')->pluck('name', 'id'))
                ->helperText('Every active account with one of these roles gets the email and a notification on the admin site.'),
            TagsInput::make('recipient_emails')->label('Also email')->placeholder('name@noahport.co.ke')
                ->nestedRecursiveRules(['email'])
                ->helperText('Addresses without an admin-site account (email only).'),
            Toggle::make('enabled')->label('On'),
        ]);
    }

    /** Normalises the form (no window or group where the metric doesn't use them) and audits the change. */
    public static function save(AlertRule $rule, array $data): AlertRule
    {
        $metric = AlertMetric::from($data['metric'] instanceof AlertMetric ? $data['metric']->value : $data['metric']);
        $rule->fill([
            ...$data,
            'window' => $metric->usesWindow() ? ($data['window'] ?? 'day') : null,
            'group_id' => $metric === AlertMetric::HandoverMissed ? null : ($data['group_id'] ?? null),
            'recipient_role_ids' => array_map('intval', $data['recipient_role_ids'] ?? []),
            'recipient_emails' => array_values($data['recipient_emails'] ?? []),
        ])->save();
        AuditLog::record($rule->wasRecentlyCreated ? 'alert_rule.created' : 'alert_rule.updated', $rule, [
            'rule' => $rule->name, 'threshold' => $rule->describeThreshold(), 'enabled' => $rule->enabled,
        ]);

        return $rule;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                TextColumn::make('name')->weight('bold')->description(fn (AlertRule $record) => $record->metric->label()),
                TextColumn::make('threshold')->state(fn (AlertRule $record) => $record->describeThreshold()),
                TextColumn::make('group.name')->label('Phones')->placeholder('All phones'),
                TextColumn::make('recipients')
                    ->state(fn (AlertRule $record) => [
                        ...AdminRole::query()->whereKey($record->recipient_role_ids ?? [])->pluck('name')->all(),
                        ...($record->recipient_emails ?? []),
                    ])
                    ->listWithLineBreaks()->placeholder('Nobody'),
                ToggleColumn::make('enabled')->label('On')
                    ->afterStateUpdated(fn (AlertRule $record) => AuditLog::record('alert_rule.updated', $record, [
                        'rule' => $record->name, 'enabled' => $record->enabled,
                    ])),
            ])
            ->recordActions([
                EditAction::make()->using(fn (AlertRule $record, array $data) => self::save($record, $data)),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAlertRules::route('/'),
        ];
    }
}
