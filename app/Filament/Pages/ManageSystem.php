<?php

namespace App\Filament\Pages;

use App\Access\Area;
use App\Access\Modules;
use App\Filament\Concerns\BoxedPage;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Einstellungen des Betreibers, nur für System-Admins: welche Module diese
 * Halle hat. Alles, was die Halle selbst einstellt, steht unter Verwaltung › Halle.
 *
 * @property-read Schema $form
 */
class ManageSystem extends Page
{
    use BoxedPage;

    protected static ?string $slug = 'system';

    protected static ?string $title = 'System';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Verwaltung';

    protected static ?int $navigationSort = 100;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->access()->isSystemAdmin();
    }

    public function getSubheading(): ?string
    {
        return 'Nur für System-Admins. Was die Halle selbst einstellt, steht unter Verwaltung › Halle.';
    }

    public function mount(): void
    {
        $disabled = Modules::current()->disabled();
        $state = [];
        foreach (Area::groups() as $group) {
            $state[self::field($group)] = array_values(array_map(
                fn (Area $area): string => $area->value,
                array_filter(self::switchable($group), fn (Area $area): bool => !in_array($area->value, $disabled, true)),
            ));
        }
        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Module')
                    ->description(fn (): string => 'Angehakt = an. Abgeschaltete Bereiche sind für alle gesperrt, auch für Admins und System-Admins; '
                        . 'vergebene Rechte bleiben erhalten und wirken wieder, sobald der Bereich an ist. Benutzer, Rollen & Rechte und Halle bleiben immer an.'
                        . (Modules::current()->isStored() ? '' : ' Bisher gilt die Server-Konfiguration (VC_DISABLED_AREAS) – ab dem ersten Speichern diese Auswahl.'))
                    ->schema(array_map(
                        fn (string $group): CheckboxList => CheckboxList::make(self::field($group))
                            ->label($group)
                            ->options(collect(self::switchable($group))->mapWithKeys(fn (Area $area): array => [$area->value => $area->label()])->all())
                            ->columns(['default' => 1, 'md' => 2])
                            ->bulkToggleable(),
                        Area::groups(),
                    )),
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
                                ->keyBindings(['mod+s']),
                        ]),
                    ]),
            ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();
        $enabled = [];
        foreach (Area::groups() as $group) {
            $enabled = [...$enabled, ...array_map('strval', (array) ($data[self::field($group)] ?? []))];
        }

        Modules::current()->disable(array_values(array_filter(
            Area::cases(),
            fn (Area $area): bool => $area->isSwitchable() && !in_array($area->value, $enabled, true),
        )));

        Notification::make()->success()->title('Gespeichert')->body('Die Navigation zeigt die Änderung nach dem nächsten Seitenwechsel.')->send();
    }

    /** @return list<Area> */
    private static function switchable(string $group): array
    {
        return array_values(array_filter(Area::inGroup($group), fn (Area $area): bool => $area->isSwitchable()));
    }

    private static function field(string $group): string
    {
        return 'areas_' . Str::slug($group, '_');
    }
}
