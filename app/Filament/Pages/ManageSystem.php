<?php

namespace App\Filament\Pages;

use App\Access\Area;
use App\Access\Module;
use App\Access\Modules;
use App\Enums\ColorScheme;
use App\Filament\Concerns\BoxedPage;
use App\Models\BackupRun;
use App\Models\Setting;
use App\Models\User;
use App\Support\Backups;
use App\Support\SecurityStatus;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Einstellungen des Betreibers, nur für System-Admins: Farbschema
 * (App\Enums\ColorScheme), welche Module die Halle gebucht hat, welche Bereiche
 * aus sind (App\Access\Modules) und die nächtliche Sicherung
 * (App\Support\Backups). Alles, was die Halle selbst einstellt, steht unter
 * Verwaltung › Halle.
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
        $disabled = Modules::current()->disabledAreas();
        $state = [
            'color_scheme' => ColorScheme::current()->value,
            'modules' => array_values(array_map(
                fn (Module $module): string => $module->value,
                array_filter(Module::cases(), fn (Module $module): bool => $module->isEnabled()),
            )),
        ];
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
                Section::make('Farbschema')
                    ->description('Gilt für alle Benutzer dieser Halle, auch auf der Anmeldeseite und der Daysheet-Seite.')
                    ->schema([
                        Radio::make('color_scheme')
                            ->label('Farbschema')
                            ->hiddenLabel()
                            ->options(collect(ColorScheme::cases())->mapWithKeys(fn (ColorScheme $scheme): array => [$scheme->value => $scheme->getLabel()])->all())
                            ->descriptions(collect(ColorScheme::cases())->mapWithKeys(fn (ColorScheme $scheme): array => [$scheme->value => new HtmlString(
                                e($scheme->description()) . '<span class="vc-swatches">'
                                . collect($scheme->swatches())->map(fn (string $color): string => '<span style="background:' . e($color) . '"></span>')->implode('')
                                . '</span>',
                            )])->all())
                            ->in(array_column(ColorScheme::cases(), 'value'))
                            ->required(),
                    ]),
                Section::make('Module')
                    ->description('Was die Halle gebucht hat. Ein Modul ohne Haken ist für alle ausgeblendet, auch für Admins und System-Admins; '
                        . 'Eingegebenes bleibt erhalten und ist wieder da, sobald das Modul freigegeben ist.')
                    ->schema([
                        CheckboxList::make('modules')
                            ->label('Module')
                            ->hiddenLabel()
                            ->options(collect(Module::cases())->mapWithKeys(fn (Module $module): array => [$module->value => $module->label()])->all())
                            ->descriptions(collect(Module::cases())->mapWithKeys(fn (Module $module): array => [$module->value => $module->description()])->all())
                            ->columns(['default' => 1, 'md' => 2]),
                    ]),
                Section::make('Bereiche')
                    ->description(fn (): string => 'Angehakt = an. Abgeschaltete Bereiche sind für alle gesperrt, auch für Admins und System-Admins; '
                        . 'vergebene Rechte bleiben erhalten und wirken wieder, sobald der Bereich an ist. Benutzer, Rollen & Rechte und Halle bleiben immer an, '
                        . 'die Buchhaltung kommt mit ihrem Modul.'
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
                Section::make('Sicherheit')
                    ->description('Was das Cockpit (ops.bein.ws) täglich aus VenueControl übernimmt; Hohes kommt dort sofort per Mail, alles andere montags im Wochenbericht.')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('security_findings')
                            ->label('Befunde')
                            ->state(fn (): array => array_map(
                                fn (array $finding): string => (['high' => 'Hoch', 'medium' => 'Mittel', 'low' => 'Gering'][$finding['severity']] ?? $finding['severity']).': '.$finding['title'],
                                SecurityStatus::findings(),
                            ) ?: ['keine'])
                            ->listWithLineBreaks(),
                        TextEntry::make('security_facts')
                            ->label('Stand')
                            ->state(fn (): array => SecurityStatus::facts())
                            ->listWithLineBreaks(),
                    ]),
                Section::make('Sicherung')
                    ->description('Jede Nacht die Datenbank, sonntags dazu die Dateien (Event-Dateien, Schadensfotos), hochgeladen in eine Datei-Ablage deiner Nextcloud. '
                        . 'Scheitert ein nächtlicher Lauf, bekommen die System-Admins eine Mail. Alte Sicherungen löscht VenueControl nicht – das geht nur in der Nextcloud.')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('backup_target')
                            ->label('Ziel')
                            ->state(fn (): string => Backups::targetLabel()),
                        TextEntry::make('backup_last')
                            ->label('Letzte gelungene Sicherung')
                            ->state(fn (): array => array_map(function (string $kind): string {
                                $run = Backups::lastOk($kind);
                                $label = $kind === BackupRun::FILES ? 'Dateien' : 'Datenbank';

                                return $label . ': ' . ($run === null ? 'noch keine' : $run->started_at?->format('d.m.Y H:i') . ' Uhr, ' . $run->sizeLabel());
                            }, [BackupRun::DATABASE, BackupRun::FILES]))
                            ->listWithLineBreaks(),
                        TextEntry::make('backup_cron')
                            ->label('Adresse für den KAS-Cronjob (täglich, z. B. 03:30)')
                            ->state(fn (): string => Backups::cronUrl() ?? 'noch keine – „Adresse erzeugen“')
                            ->copyable(fn (): bool => Backups::cronUrl() !== null)
                            ->copyMessage('Adresse kopiert')
                            ->helperText('Wer die Adresse kennt, kann eine Sicherung auslösen, aber nichts lesen. E-Mail-Ausgabe im KAS frei lassen – Fehler meldet VenueControl selbst.')
                            ->columnSpanFull(),
                        Actions::make([
                            Action::make('backupNow')
                                ->label('Jetzt sichern')
                                ->icon(Heroicon::OutlinedCloudArrowUp)
                                ->requiresConfirmation()
                                ->modalDescription('Sichert Datenbank und Dateien sofort und lädt beides in die Nextcloud.')
                                ->action(fn () => $this->backupNow()),
                            Action::make('newCronKey')
                                ->label(fn (): string => Backups::cronUrl() === null ? 'Adresse erzeugen' : 'Neue Adresse erzeugen')
                                ->color('gray')
                                ->requiresConfirmation(fn (): bool => Backups::cronUrl() !== null)
                                ->modalDescription('Die bisherige Adresse funktioniert danach nicht mehr – im KAS-Cronjob die neue eintragen.')
                                ->action(function (): void {
                                    abort_unless(static::canAccess(), 403);
                                    Backups::newCronKey();
                                    Notification::make()->success()->title('Adresse erzeugt')->body('Jetzt im KAS einen täglichen Cronjob mit dieser Adresse anlegen.')->send();
                                }),
                        ])->key('backupActions')->columnSpanFull(),
                        View::make('filament.system.backup-runs')
                            ->viewData(fn (): array => ['runs' => BackupRun::query()->latest('started_at')->latest('id')->limit(10)->get()])
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private function backupNow(): void
    {
        abort_unless(static::canAccess(), 403);

        $runs = Backups::run('manuell', withFiles: true);
        if ($runs === null) {
            Notification::make()->warning()->title('Es läuft gerade schon eine Sicherung.')->send();

            return;
        }

        $failed = $runs->firstWhere('status', BackupRun::FAILED);
        if ($failed !== null) {
            Notification::make()->danger()->title('Sicherung fehlgeschlagen')->body($failed->kindLabel() . ': ' . $failed->error)->persistent()->send();

            return;
        }

        Notification::make()
            ->success()
            ->title('Gesichert')
            ->body($runs->map(fn (BackupRun $run): string => $run->kindLabel() . ': ' . $run->sizeLabel())->implode(', '))
            ->send();
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();
        $enabled = [];
        foreach (Area::groups() as $group) {
            $enabled = [...$enabled, ...array_map('strval', (array) ($data[self::field($group)] ?? []))];
        }

        $modules = array_map('strval', (array) ($data['modules'] ?? []));
        Modules::current()->store(
            array_values(array_filter(Module::cases(), fn (Module $module): bool => in_array($module->value, $modules, true))),
            array_values(array_filter(Area::cases(), fn (Area $area): bool => $area->isSwitchable() && !in_array($area->value, $enabled, true))),
        );

        $scheme = ColorScheme::tryFrom((string) ($data['color_scheme'] ?? '')) ?? ColorScheme::VcBasic;
        $schemeChanged = $scheme !== ColorScheme::current();
        Setting::put(Setting::COLOR_SCHEME, $scheme->value);

        Notification::make()->success()->title('Gespeichert')->body('Die Navigation zeigt die Änderung nach dem nächsten Seitenwechsel.')->send();

        // Die Farben stehen im Kopf der Seite – neu laden, damit das Schema sofort gilt
        if ($schemeChanged) {
            $this->redirect(static::getUrl());
        }
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
