<?php

namespace App\Filament\Resources\Crews\Pages;

use App\Filament\Resources\Crews\CrewResource;
use App\Mdm\Models\Crew;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCrews extends ManageRecords
{
    protected static string $resource = CrewResource::class;

    public function getSubheading(): ?string
    {
        return 'Each crew has two coordinators and its clerks (set a clerk\'s crew on Clerks or in the spreadsheet). The Roster says which crew works which shift.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->using(fn (array $data) => CrewResource::save(new Crew, $data)),
        ];
    }
}
