<?php

namespace App\Filament\Imports;

use App\Mdm\Models\Clerk;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Number;

/** Loads clerks from the company's staff spreadsheet (CSV: staff_number, name[, active]). Re-importing updates by staff number. */
class ClerkImporter extends Importer
{
    protected static ?string $model = Clerk::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('staff_number')
                ->label('Staff number')
                ->guess(['staff no', 'staff_no', 'staff number', 'payroll', 'employee number', 'employee no', 'id'])
                ->requiredMapping()
                ->rules(['required', 'max:40']),
            ImportColumn::make('name')
                ->label('Name')
                ->guess(['full name', 'clerk', 'employee', 'names'])
                ->requiredMapping()
                ->rules(['required', 'max:120']),
            ImportColumn::make('active')
                ->label('Active')
                ->boolean()
                ->rules(['nullable', 'boolean']),
        ];
    }

    public function resolveRecord(): Clerk
    {
        return Clerk::firstOrNew(['staff_number' => trim((string) $this->data['staff_number'])]);
    }

    protected function beforeSave(): void
    {
        $this->record->active ??= true;
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Clerk import finished: '.Number::format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed; download them to see why.';
        }

        return $body;
    }
}
