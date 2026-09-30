<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use App\Models\Event;
use App\Support\EventDisplay;
use App\Support\StagePlan;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Bühnenplan wie in der PHP-Version: Draufsicht mit Podest-Raster, daneben die
 * Plan-Einstellungen (Versatz der Wings und Treppen, Abstand zur Rückwand).
 * Die Maße selbst stehen im Workspace unter Planung › Bühne.
 *
 * @property-read Schema $form
 */
class StagePlanPage extends Page
{
    use InteractsWithRecord;

    protected static string $resource = EventResource::class;

    protected static ?string $title = 'Bühnenplan';

    protected static ?string $breadcrumb = 'Bühnenplan';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(array $parameters = []): bool
    {
        $record = $parameters['record'] ?? null;

        return $record instanceof Event ? EventResource::canView($record) : EventResource::canViewAny();
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $stage = $this->event()->stage;

        $this->form->fill([
            'wing_sl_offset' => $stage?->wing_sl_offset ?? StagePlan::DEFAULT_WING_OFFSET,
            'wing_sr_offset' => $stage?->wing_sr_offset ?? StagePlan::DEFAULT_WING_OFFSET,
            'stair_sl_offset' => $stage?->stair_sl_offset ?? 0,
            'stair_sr_offset' => $stage?->stair_sr_offset ?? 0,
            'backwall_cm' => $stage?->backwall_cm ?? StagePlan::DEFAULT_BACKWALL_CM,
        ]);
    }

    public function getSubheading(): string
    {
        return $this->event()->title . ' · ' . EventDisplay::meta($this->event()) . ' — ' . $this->plan()['subtitle'];
    }

    public function form(Schema $schema): Schema
    {
        $meters = fn (string $name, string $label): TextInput => TextInput::make($name)
            ->label($label)
            ->integer()
            ->minValue(0)
            ->maxValue(20)
            ->suffix('m')
            ->required();

        return $schema
            ->statePath('data')
            ->disabled(fn (): bool => !$this->canEdit())
            ->components([
                $meters('wing_sl_offset', 'Wing SL – Versatz Vorderkante')
                    ->helperText('Richtung Upstage, weg vom Publikum'),
                $meters('wing_sr_offset', 'Wing SR – Versatz Vorderkante'),
                $meters('stair_sl_offset', 'Treppe SL – Versatz Hinterkante')
                    ->helperText('Richtung Publikum, Standard 0'),
                $meters('stair_sr_offset', 'Treppe SR – Versatz Hinterkante'),
                TextInput::make('backwall_cm')
                    ->label('Abstand zur Rückwand')
                    ->helperText('Bühnenkonstruktion bis Rückwand, Standard ' . StagePlan::DEFAULT_BACKWALL_CM)
                    ->integer()
                    ->minValue(0)
                    ->maxValue(1000)
                    ->suffix('cm')
                    ->required(),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['default' => 1, 'xl' => 4])
                    ->schema([
                        Group::make([
                            Section::make('Plan-Einstellungen')
                                ->schema([
                                    Form::make([EmbeddedSchema::make('form')])
                                        ->id('form')
                                        ->livewireSubmitHandler('save')
                                        ->footer([
                                            Actions::make([
                                                Action::make('save')
                                                    ->label('Aktualisieren')
                                                    ->submit('save')
                                                    ->visible(fn (): bool => $this->canEdit()),
                                            ]),
                                        ]),
                                ]),
                            Section::make('Podeste')
                                ->schema([
                                    View::make('filament.events.stage-plan-stats')
                                        ->viewData(fn (): array => ['plan' => $this->plan()]),
                                ]),
                        ])->columnSpan(['xl' => 1]),
                        Section::make()
                            ->schema([
                                View::make('filament.events.stage-plan-canvas')
                                    ->viewData(fn (): array => ['svg' => StagePlan::toSvg($this->plan())]),
                            ])
                            ->columnSpan(['xl' => 3]),
                    ]),
            ]);
    }

    public function save(): void
    {
        abort_unless($this->canEdit(), 403);

        $data = $this->form->getState();
        $this->event()->stage()->updateOrCreate([], [
            'wing_sl_offset' => (int) $data['wing_sl_offset'],
            'wing_sr_offset' => (int) $data['wing_sr_offset'],
            'stair_sl_offset' => (int) $data['stair_sl_offset'],
            'stair_sr_offset' => (int) $data['stair_sr_offset'],
            'backwall_cm' => (int) $data['backwall_cm'],
        ]);
        $this->event()->unsetRelation('stage');

        Notification::make()->success()->title('Bühnenplan aktualisiert')->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label('Drucken / PDF')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(fn (): string => route('events.stage-plan-print', $this->event()))
                ->openUrlInNewTab(),
            Action::make('svg')
                ->label('SVG')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->url(fn (): string => route('events.stage-plan-svg', $this->event())),
            Action::make('stage')
                ->label('Zur Bühne')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->url(fn (): string => EventResource::getUrl($this->canEdit() ? 'edit' : 'view', ['record' => $this->event()]) . '?phase=planung&bereich=buehne'),
        ];
    }

    /** @return array<string, mixed> */
    private function plan(): array
    {
        return StagePlan::build($this->event()->stage, $this->event());
    }

    private function event(): Event
    {
        /** @var Event */
        return $this->getRecord();
    }

    private function canEdit(): bool
    {
        return EventResource::canEdit($this->event());
    }
}
