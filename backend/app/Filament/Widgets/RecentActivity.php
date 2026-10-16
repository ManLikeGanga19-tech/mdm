<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Mdm\Models\AuditLog;
use App\Mdm\Permission;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** The latest audited actions: handovers, commands, rule changes. */
class RecentActivity extends TableWidget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Recent activity';

    public const LABELS = [
        'handover' => 'Handover',
        'phone_returned' => 'Phone returned',
        'command.lock' => 'Lock sent',
        'command.reboot' => 'Restart sent',
        'command.reset_password' => 'Screen lock reset sent',
        'command.wipe' => 'Erase sent',
        'policy.updated' => 'Group rules changed',
        'policy.override' => 'Phone rule changed',
        'device.updated' => 'Phone edited',
        'device.enrolled' => 'Phone enrolled',
        'app.blocked' => 'App blocked',
        'app.allowed' => 'App allowed',
        'command.succeeded' => 'Command done',
        'command.failed' => 'Command failed',
        'work_rule.created' => 'Work site added',
        'work_rule.updated' => 'Work site changed',
        'work_rule.deleted' => 'Work site removed',
        'enrollment_code.created' => 'Enrollment code made',
        'enrollment_code.disabled' => 'Enrollment code disabled',
        'settings.peak_hours' => 'Peak hours changed',
        'handover_lock.released' => 'Handover lock released',
        'live_view.started' => 'Live view started',
        'live_view.stopped' => 'Live view stopped',
        'command.live_view' => 'Live view sent',
    ];

    public static function canView(): bool
    {
        return auth()->user()?->hasPermission(Permission::FleetView) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(AuditLog::query()->with('actor')->latest('created_at')->limit(8))
            ->paginated(false)
            ->emptyStateHeading('No activity yet')
            ->headerActions([
                Action::make('all')->label('Full audit log')->link()->url(AuditLogResource::getUrl()),
            ])
            ->columns([
                TextColumn::make('created_at')->label('When')->since()->tooltip(fn (AuditLog $r) => $r->created_at->toDayDateTimeString()),
                TextColumn::make('action')->formatStateUsing(fn (string $state) => self::LABELS[$state] ?? $state)->badge()->color('gray'),
                TextColumn::make('data.asset_tag')->label('Phone')->placeholder('-'),
                TextColumn::make('actor.name')->label('By')->placeholder('System'),
            ]);
    }
}
