<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\AdminRoleResource;
use App\Mdm\Models\AdminRole;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageRoles extends ManageRecords
{
    protected static string $resource = AdminRoleResource::class;

    public function getSubheading(): ?string
    {
        return 'Tick what each role may do. Changes apply on the next click of everyone with that role. Super admin always has everything.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New role')
                ->using(fn (array $data) => AdminRoleResource::save(new AdminRole, $data)),
        ];
    }
}
