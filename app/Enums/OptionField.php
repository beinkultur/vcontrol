<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Event-Felder mit pflegbaren Auswahlwerten (Tabelle field_options). */
enum OptionField: string implements HasLabel
{
    case VaStatus = 'va_status';
    case Areas = 'areas';
    case Seating = 'seating';
    case Ticketing = 'ticketing';
    case PriceList = 'price_list';
    case PrStatus = 'pr_status';
    case VaType1 = 'va_type1';
    case VaType2 = 'va_type2';
    case EmployeePosition = 'employee_position';
    case ContractStatus = 'contract_status';
    case AccountingStatus = 'accounting_status';

    public function getLabel(): string
    {
        return match ($this) {
            self::VaStatus => 'VA-Status',
            self::Areas => 'Bereiche',
            self::Seating => 'Bestuhlung',
            self::Ticketing => 'Ticketing',
            self::PriceList => 'Preisliste',
            self::PrStatus => 'PR-Status',
            self::VaType1 => 'VA-Kategorie 1',
            self::VaType2 => 'VA-Kategorie 2',
            self::EmployeePosition => 'Mitarbeiter-Position',
            self::ContractStatus => 'Vertragsstatus',
            self::AccountingStatus => 'FIBU-Status',
        };
    }

    /** Feld, dessen Wert als übergeordneter Wert gewählt wird (nur VA-Kategorie 2). */
    public function parent(): ?self
    {
        return $this === self::VaType2 ? self::VaType1 : null;
    }
}
