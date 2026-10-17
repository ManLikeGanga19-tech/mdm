<?php

namespace App\Filament\Resources\Alerts\Pages;

use App\Filament\Resources\AlertRules\AlertRuleResource;
use App\Filament\Resources\Alerts\AlertResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageAlerts extends ManageRecords
{
    protected static string $resource = AlertResource::class;

    public function getSubheading(): ?string
    {
        return 'Open alerts stay here until the problem clears (e.g. the phone checks in again) or someone acknowledges them.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('rules')->label('Alert rules')->icon(Heroicon::OutlinedAdjustmentsHorizontal)->color('gray')
                ->url(fn () => AlertRuleResource::getUrl())
                ->visible(fn () => AlertRuleResource::canViewAny()),
        ];
    }
}
