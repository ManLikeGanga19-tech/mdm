<?php

namespace App\Filament\Pages\ShiftReport;

use App\Mdm\Models\Shift;
use App\Mdm\Services\ShiftReport;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** How the handover that opens the shift went, per phone: on time, early, late or missing; who and to whom. */
class ShiftHandoverTable extends TableWidget
{
    public ?int $shiftId = null;

    public ?string $date = null;

    protected static ?string $heading = 'Handover';

    public function table(Table $table): Table
    {
        return $table
            ->records(function () {
                $shift = Shift::find($this->shiftId);

                return $shift ? app(ShiftReport::class)->build($shift, $this->date)['handovers']
                    ->mapWithKeys(fn (array $h) => [$h['device']->id => [
                        'asset_tag' => $h['device']->asset_tag, 'status' => $h['status'],
                        'at' => $h['at'], 'by' => $h['by'], 'to' => $h['to'],
                    ]])->all() : [];
            })
            ->paginated(false)
            ->emptyStateHeading('No enrolled phones')
            ->columns(HandoverColumns::make());
    }
}
