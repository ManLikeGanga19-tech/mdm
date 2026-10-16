<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Mdm\Models\AdminRole;
use App\Mdm\Permission;
use App\Mdm\Services\AccountService;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Admin-site accounts: invite (set-password link), role, disable/enable, new set-password link, last sign-in.
 * The rules (never lose the last super admin, audit) live in AccountService.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $modelLabel = 'admin-site account';

    protected static ?string $navigationLabel = 'Accounts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?int $navigationSort = 9;

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasPermission(Permission::AccountsManage) ?? false;
    }

    public static function canCreate(): bool
    {
        return false; // accounts are invited (ManageUsers), never given a password by someone else
    }

    public static function canDelete(Model $record): bool
    {
        return (auth()->user()?->hasPermission(Permission::AccountsManage) ?? false) && $record->isNot(auth()->user());
    }

    public static function roleOptions(): array
    {
        return AdminRole::query()->orderBy('id')->pluck('name', 'id')->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(120),
            TextInput::make('email')->email()->required()->unique(ignoreRecord: true),
            Select::make('role_id')->label('Role')->options(fn () => self::roleOptions())->required()
                ->helperText('What each role may do: Roles & permissions.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('role.name')->label('Role')->badge(),
                TextColumn::make('status')
                    ->state(fn (User $record) => match (true) {
                        $record->isDisabled() => 'Disabled',
                        $record->last_login_at === null && $record->invited_at !== null => 'Invited',
                        default => 'Active',
                    })
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'Disabled' => 'danger', 'Invited' => 'warning', default => 'success',
                    }),
                TextColumn::make('last_login_at')->label('Last sign-in')->since()->placeholder('Never')->sortable(),
            ])
            ->filters([
                SelectFilter::make('role_id')->label('Role')->options(fn () => self::roleOptions()),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(function (User $record, array $data) {
                        $record->update(['name' => $data['name'], 'email' => $data['email']]);
                        self::attempt(fn () => app(AccountService::class)->changeRole($record, AdminRole::findOrFail($data['role_id'])));

                        return $record;
                    }),
                ActionGroup::make([
                    Action::make('setPasswordLink')
                        ->label('New set-password link')
                        ->icon(Heroicon::OutlinedLink)
                        ->requiresConfirmation()
                        ->modalDescription('Their current password stops working and they are signed out. You get a link (valid 48 hours) to send them; they choose a new password with it.')
                        ->action(fn (User $record) => self::showLink($record, app(AccountService::class)->resetPassword($record))),
                    Action::make('disable')
                        ->icon(Heroicon::OutlinedNoSymbol)
                        ->color('danger')
                        ->visible(fn (User $record) => ! $record->isDisabled() && $record->isNot(auth()->user()))
                        ->requiresConfirmation()
                        ->modalDescription('They are signed out and can no longer sign in. Their history stays.')
                        ->action(fn (User $record) => self::attempt(fn () => app(AccountService::class)->disable($record), 'Account disabled')),
                    Action::make('enable')
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->color('success')
                        ->visible(fn (User $record) => $record->isDisabled())
                        ->action(fn (User $record) => self::attempt(fn () => app(AccountService::class)->enable($record), 'Account enabled')),
                    DeleteAction::make()
                        ->using(fn (User $record) => self::attempt(fn () => app(AccountService::class)->delete($record))),
                ]),
            ]);
    }

    /** Shows a set-password link to copy (until mail is set up, the admin sends it to the person). */
    public static function showLink(User $user, string $link): void
    {
        Notification::make()
            ->title("Set-password link for {$user->name}")
            ->body("Send this to {$user->email}. It works once, for 48 hours:\n\n{$link}")
            ->success()
            ->persistent()
            ->send();
    }

    /** Runs an account change; a broken rule (e.g. the last super admin) is shown instead of failing the page. */
    private static function attempt(callable $change, ?string $success = null): bool
    {
        try {
            $change();
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return false;
        }
        if ($success) {
            Notification::make()->title($success)->success()->send();
        }

        return true;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUsers::route('/'),
        ];
    }
}
