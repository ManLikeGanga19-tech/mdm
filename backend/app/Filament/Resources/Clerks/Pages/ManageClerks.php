<?php

namespace App\Filament\Resources\Clerks\Pages;

use App\Filament\Imports\ClerkImporter;
use App\Filament\Resources\Clerks\ClerkResource;
use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ManageRecords;

class ManageClerks extends ManageRecords
{
    protected static string $resource = ClerkResource::class;

    protected function getHeaderActions(): array
    {
        $superAdmin = fn () => auth()->user()->isSuperAdmin();

        return [
            ImportAction::make()
                ->label('Import spreadsheet')
                ->importer(ClerkImporter::class)
                ->visible($superAdmin),
            CreateAction::make()->visible($superAdmin),
        ];
    }
}
