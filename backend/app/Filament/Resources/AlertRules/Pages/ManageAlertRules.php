<?php

namespace App\Filament\Resources\AlertRules\Pages;

use App\Filament\Resources\AlertRules\AlertRuleResource;
use App\Mdm\Models\AlertRule;
use App\Mdm\Notifications\AccountEmail;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAlertRules extends ManageRecords
{
    protected static string $resource = AlertRuleResource::class;

    public function getSubheading(): ?string
    {
        return 'Checked every 5 minutes. Each alert is raised once and shown on the Alerts page'
            .(AccountEmail::canSend() ? '; recipients get an email.' : '; emails start once the site\'s mail server is set up.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->using(fn (array $data) => AlertRuleResource::save(new AlertRule, $data)),
        ];
    }
}
