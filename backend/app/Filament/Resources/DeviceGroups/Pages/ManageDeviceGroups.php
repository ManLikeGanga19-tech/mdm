<?php

namespace App\Filament\Resources\DeviceGroups\Pages;

use App\Filament\Resources\DeviceGroups\DeviceGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDeviceGroups extends ManageRecords
{
    protected static string $resource = DeviceGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
