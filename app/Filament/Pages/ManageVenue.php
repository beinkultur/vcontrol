<?php

namespace App\Filament\Pages;

use App\Access\Area;
use App\Models\Setting;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Einstellungen dieser Halle (in der PHP-Version „Stammdaten“). Jede Halle hat
 * eine eigene Installation – hier steht, welche es ist.
 *
 * @property-read Schema $form
 */
class ManageVenue extends Page
{
    protected static ?string $slug = 'halle';

    protected static ?string $title = 'Halle';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Verwaltung';

    protected static ?int $navigationSort = 40;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->access()->can(Area::AdminStammdaten);
    }

    public function mount(): void
    {
        $this->form->fill([
            'venue_name' => Setting::lookup(Setting::VENUE_NAME),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->disabled(fn (): bool => !$this->canEdit())
            ->components([
                Section::make('Stammdaten der Halle')
                    ->schema([
                        TextInput::make('venue_name')
                            ->label('Name der Halle')
                            ->helperText('Erscheint in der Kopfzeile neben „VenueControl“.')
                            ->required()
                            ->maxLength(120),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label('Speichern')
                                ->submit('save')
                                ->keyBindings(['mod+s'])
                                ->visible(fn (): bool => $this->canEdit()),
                        ]),
                    ]),
            ]);
    }

    public function save(): void
    {
        abort_unless($this->canEdit(), 403);

        $data = $this->form->getState();
        Setting::put(Setting::VENUE_NAME, trim((string) $data['venue_name']));

        Notification::make()->success()->title('Gespeichert')->send();
    }

    private function canEdit(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->access()->canEdit(Area::AdminStammdaten);
    }
}
