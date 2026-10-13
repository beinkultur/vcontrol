<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Contact;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ManageContacts extends ManageRecords
{
    protected static string $resource = ContactResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function getSubheading(): ?string
    {
        return 'Personen und Funktionen ohne Anmeldung, z. B. „Hallenmeister“. Wer ein Konto hat, bekommt Positionen unter Verwaltung › Benutzer. Löschen geht nur, solange kein Event auf den Kontakt verweist – sonst archivieren.';
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            'aktiv' => Tab::make('Aktiv')
                ->badge(Contact::where('is_archived', false)->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_archived', false)),
            'archiv' => Tab::make('Archiv')
                ->badge(Contact::where('is_archived', true)->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_archived', true)),
        ];
    }
}
