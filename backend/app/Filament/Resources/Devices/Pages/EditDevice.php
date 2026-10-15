<?php

namespace App\Filament\Resources\Devices\Pages;

use App\Filament\Resources\Devices\DeviceResource;
use App\Mdm\Models\AuditLog;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditDevice extends EditRecord
{
    protected static string $resource = DeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        $changes = $this->record->getChanges();
        unset($changes['updated_at']);
        if ($changes) {
            AuditLog::record('device.updated', $this->record, ['asset_tag' => $this->record->asset_tag, 'changes' => $changes]);
        }
    }
}
