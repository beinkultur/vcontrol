<?php

namespace App\Filament\Resources\FieldOptions\Pages;

use App\Enums\OptionField;
use App\Filament\Resources\FieldOptions\FieldOptionResource;
use App\Models\FieldOption;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ManageFieldOptions extends ManageRecords
{
    protected static string $resource = FieldOptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                // Neuer Wert gehört zum gerade geöffneten Feld und kommt ans Ende
                ->fillForm(fn (): array => ['field_key' => $this->activeTab, 'is_active' => true])
                ->mutateDataUsing(fn (array $data): array => $data + [
                    'sort_order' => (int) FieldOption::query()
                        ->where('field_key', FieldOptionResource::fieldOf($data['field_key'] ?? null)?->value)
                        ->max('sort_order') + 10,
                ]),
        ];
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $counts = FieldOption::query()->selectRaw('field_key, COUNT(*) AS n')->groupBy('field_key')->pluck('n', 'field_key');

        $tabs = [];
        foreach (OptionField::cases() as $field) {
            $tabs[$field->value] = Tab::make($field->getLabel())
                ->badge($counts[$field->value] ?? 0)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('field_key', $field->value));
        }

        return $tabs;
    }
}
