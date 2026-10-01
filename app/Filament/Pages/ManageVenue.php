<?php

namespace App\Filament\Pages;

use App\Access\Area;
use App\Filament\Concerns\BoxedPage;
use App\Models\Setting;
use App\Models\User;
use App\Support\EventIcsFeed;
use App\Support\StagePlan;
use App\Support\StagePodests;
use App\Support\StageSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Einstellungen dieser Halle (in der PHP-Version „Stammdaten“). Jede Halle hat
 * eine eigene Installation – hier steht, welche es ist.
 *
 * @property-read Schema $form
 */
class ManageVenue extends Page
{
    use BoxedPage;

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
        $stage = StageSettings::load();
        $this->form->fill([
            'venue_name' => Setting::lookup(Setting::VENUE_NAME),
            'podest_inventory' => $stage->inventory,
            'podest_included' => $stage->included,
            'stage_base_width' => $stage->baseWidth,
            'stage_base_depth' => $stage->baseDepth,
            'stage_house_2x1' => $stage->house2x1,
            'stage_house_1x1' => $stage->house1x1,
            'stage_roll_width' => $stage->rollWidth,
            'stage_roll_depth' => $stage->rollDepth,
            'stage_height' => $stage->height,
            'stage_heights' => array_map(StagePodests::formatMeters(...), $stage->heights),
            'stage_label' => $stage->label,
            'stage_boundary' => $stage->boundary,
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
                Section::make('Bühne')
                    ->description('Grundlage für Podest-Rechnung, Event-Liste, Buchhaltung und Bühnenplan. Voreingestellt sind die Werte der Inselpark Arena.')
                    ->schema([
                        Grid::make(['default' => 1, 'lg' => 2])
                            ->schema([
                                Fieldset::make('Standardbühne')
                                    ->columns(2)
                                    ->schema([
                                        self::meters('stage_base_width', 'Breite')
                                            ->minValue(2)
                                            ->maxValue(24)
                                            ->multipleOf(2)
                                            ->helperText('Gerade Zahl, ein Podest ist 2 m breit.'),
                                        self::meters('stage_base_depth', 'Tiefe')
                                            ->minValue(1)
                                            ->maxValue(14),
                                    ]),
                                Fieldset::make('Zusatzpodeste im Hausbestand')
                                    ->columns(2)
                                    ->schema([
                                        self::pieces('stage_house_2x1', 'Podeste 2 × 1 m')->live(onBlur: true),
                                        self::pieces('stage_house_1x1', 'Podeste 1 × 1 m'),
                                    ]),
                                Fieldset::make('Rollipodest')
                                    ->columns(2)
                                    ->schema([
                                        self::meters('stage_roll_width', 'Breite')
                                            ->minValue(0)
                                            ->maxValue(20)
                                            ->helperText('Gilt, wenn am Event nichts eingetragen ist; 0 = keins.'),
                                        self::meters('stage_roll_depth', 'Tiefe')
                                            ->minValue(0)
                                            ->maxValue(20),
                                    ]),
                                Fieldset::make('Höhe')
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('stage_height')
                                            ->label('Standardhöhe')
                                            ->helperText('Andere Höhen markiert die Event-Liste gelb.')
                                            ->numeric()
                                            ->step(0.1)
                                            ->minValue(0.1)
                                            ->maxValue(3)
                                            ->suffix('m')
                                            ->required(),
                                        TagsInput::make('stage_heights')
                                            ->label('Wählbare Höhen')
                                            ->helperText('In Metern, z. B. 1,4. Die Standardhöhe ist immer wählbar.')
                                            ->placeholder('Höhe + Enter')
                                            ->splitKeys(['Tab', ' ', ';'])
                                            ->nestedRecursiveRules(['regex:/^\d([.,]\d{1,2})?$/'])
                                            ->required(),
                                    ]),
                                Fieldset::make('Podeste')
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('podest_inventory')
                                            ->label('Podeste im Bestand')
                                            ->helperText(fn (Get $get): string => 'Mehr an einem Event wird rot markiert: Nachbestellung nötig. Aus den Werten oben: '
                                                . self::podestsHint($get, withHouse: true) . '.')
                                            ->integer()
                                            ->minValue(0)
                                            ->required(),
                                        TextInput::make('podest_included')
                                            ->label('Davon im Mietpreis enthalten')
                                            ->helperText(fn (Get $get): string => 'Darüber hinaus wird zusätzlich berechnet. Aus den Werten oben: '
                                                . self::podestsHint($get, withHouse: false) . '.')
                                            ->integer()
                                            ->minValue(0)
                                            ->required(),
                                    ]),
                                Fieldset::make('Bühnenplan')
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('stage_label')
                                            ->label('Beschriftung')
                                            ->helperText('Steht vor den Maßen, z. B. „IPA stage 14×8×1.4m“.')
                                            ->required()
                                            ->maxLength(40),
                                        TextInput::make('stage_boundary')
                                            ->label('Raumbegrenzung')
                                            ->helperText('Grüne Linien (Notausgänge) links und rechts der Mitte. Der Planrahmen 25 × 15 m ist fest, er legt nur das Seitenverhältnis fest.')
                                            ->numeric()
                                            ->step(0.5)
                                            ->minValue(1)
                                            ->maxValue(StagePlan::ROOM_WIDTH / 2)
                                            ->prefix('±')
                                            ->suffix('m')
                                            ->required(),
                                    ]),
                            ]),
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
                // Kalender-Feed wie in der PHP-Version: ein Abo für alle, mit geheimem Schlüssel
                Section::make('Kalender-Feed')
                    ->description('Alle bestätigten Veranstaltungen als Abo für Kalender-Apps (Outlook, Google, Apple). Die Adresse enthält einen geheimen Schlüssel – wer sie hat, sieht die Termine.')
                    ->schema([
                        TextEntry::make('feed_url')
                            ->label('Adresse')
                            ->state(fn (): string => EventIcsFeed::url() ?? 'ausgeschaltet')
                            ->copyable(fn (): bool => EventIcsFeed::isEnabled())
                            ->copyMessage('Adresse kopiert'),
                        Actions::make([
                            Action::make('enableFeed')
                                ->label(fn (): string => EventIcsFeed::isEnabled() ? 'Neue Adresse erzeugen' : 'Feed einschalten')
                                ->requiresConfirmation(fn (): bool => EventIcsFeed::isEnabled())
                                ->modalDescription('Die bisherige Adresse funktioniert danach nicht mehr – bestehende Abos müssen neu eingerichtet werden.')
                                ->visible(fn (): bool => $this->canEdit())
                                ->action(function (): void {
                                    Setting::put(Setting::CALENDAR_FEED_TOKEN, Str::random(40));
                                    Notification::make()->success()->title('Kalender-Feed eingeschaltet')->send();
                                }),
                            Action::make('disableFeed')
                                ->label('Ausschalten')
                                ->color('danger')
                                ->requiresConfirmation()
                                ->modalDescription('Kalender-Apps bekommen danach keine Termine mehr.')
                                ->visible(fn (): bool => $this->canEdit() && EventIcsFeed::isEnabled())
                                ->action(function (): void {
                                    Setting::put(Setting::CALENDAR_FEED_TOKEN, null);
                                    Notification::make()->success()->title('Kalender-Feed ausgeschaltet')->send();
                                }),
                        ])->key('feedActions'),
                    ]),
            ]);
    }

    public function save(): void
    {
        abort_unless($this->canEdit(), 403);

        $data = $this->form->getState();
        Setting::put(Setting::VENUE_NAME, trim((string) $data['venue_name']));

        $decimal = fn (mixed $value): float => round((float) str_replace(',', '.', (string) $value), 2);
        $stage = [
            Setting::PODEST_INVENTORY => (int) $data['podest_inventory'],
            Setting::PODEST_INCLUDED => (int) $data['podest_included'],
            'stage_base_width' => (int) $data['stage_base_width'],
            'stage_base_depth' => (int) $data['stage_base_depth'],
            'stage_house_2x1' => (int) $data['stage_house_2x1'],
            'stage_house_1x1' => (int) $data['stage_house_1x1'],
            'stage_roll_width' => (int) $data['stage_roll_width'],
            'stage_roll_depth' => (int) $data['stage_roll_depth'],
            'stage_height' => $decimal($data['stage_height']),
            'stage_heights' => StageSettings::formatHeights(StageSettings::parseHeights(implode(';', (array) $data['stage_heights']))),
            'stage_label' => trim((string) $data['stage_label']),
            'stage_boundary' => $decimal($data['stage_boundary']),
        ];
        foreach ($stage as $key => $value) {
            Setting::put($key, (string) $value);
        }
        StageSettings::forget();

        Notification::make()->success()->title('Gespeichert')->send();
    }

    /** Ganze Meter, live für die Rechenhilfe bei Bestand und Mietanteil. */
    private static function meters(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->integer()
            ->suffix('m')
            ->required()
            ->live(onBlur: true);
    }

    private static function pieces(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->integer()
            ->minValue(0)
            ->maxValue(999)
            ->suffix('Stück')
            ->required();
    }

    /** „56 Standardbühne + 24 Zusatzpodeste + 6 Rollipodest = 86“ aus den Eingaben. */
    private static function podestsHint(Get $get, bool $withHouse): string
    {
        $base = intdiv((int) $get('stage_base_width'), 2) * (int) $get('stage_base_depth');
        $house = $withHouse ? (int) $get('stage_house_2x1') : 0;
        $roll = intdiv((int) $get('stage_roll_width') * (int) $get('stage_roll_depth'), 2);
        $parts = array_filter([
            "{$base} Standardbühne",
            $withHouse ? "{$house} Zusatzpodeste" : null,
            $roll > 0 ? "{$roll} Rollipodest" : null,
        ]);

        return implode(' + ', $parts) . ' = ' . ($base + $house + $roll);
    }

    private function canEdit(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->access()->canEdit(Area::AdminStammdaten);
    }
}
