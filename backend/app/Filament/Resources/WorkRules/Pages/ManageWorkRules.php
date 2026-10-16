<?php

namespace App\Filament\Resources\WorkRules\Pages;

use App\Filament\Resources\WorkRules\WorkRuleResource;
use App\Mdm\Models\AuditLog;
use App\Mdm\Models\WorkRule;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageWorkRules extends ManageRecords
{
    protected static string $resource = WorkRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add work site or app')
                ->after(fn (WorkRule $record) => AuditLog::record('work_rule.created', $record, $record->only('kind', 'pattern', 'label'))),
        ];
    }
}
