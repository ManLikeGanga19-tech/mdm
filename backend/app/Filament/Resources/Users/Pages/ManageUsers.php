<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Mdm\Models\AdminRole;
use App\Mdm\Role;
use App\Mdm\Services\AccountService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageUsers extends ManageRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('invite')
                ->label('Invite')
                ->icon(Heroicon::OutlinedUserPlus)
                ->modalDescription('The account is created without a password. You get a link (valid 48 hours) to send them; they choose their own password with it.')
                ->schema([
                    TextInput::make('name')->required()->maxLength(120),
                    TextInput::make('email')->email()->required()->unique('users', 'email'),
                    Select::make('role_id')->label('Role')->options(fn () => UserResource::roleOptions())->required()
                        ->default(fn () => Role::Coordinator->model()->id),
                ])
                ->action(function (array $data) {
                    $invite = app(AccountService::class)->invite($data['name'], $data['email'], AdminRole::findOrFail($data['role_id']));
                    UserResource::showLink($invite['user'], $invite['link']);
                }),
        ];
    }
}
