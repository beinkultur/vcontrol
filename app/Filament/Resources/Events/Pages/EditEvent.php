<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Concerns\BoxedPage;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\Concerns\HasEventHeading;
use App\Filament\Support\DaysheetActions;
use App\Filament\Resources\Events\Schemas\EventForm;
use Filament\Forms\Components\Field;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Filament\Support\Enums\Alignment;

/**
 * Der Event-Workspace: Übersicht und Phasen (siehe EventForm). Events werden
 * storniert, nicht gelöscht – daher keine Lösch-Aktion.
 */
class EditEvent extends EditRecord
{
    use BoxedPage;
    use HasEventHeading;

    protected static string $resource = EventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->stagePlanAction(),
            $this->externViewAction(),
            DaysheetActions::send(),
            $this->historyAction(),
        ];
    }

    /** Speichern/Abbrechen unten rechtsbündig, wie oben in der Reiterzeile (EventForm::SECTION_ACTIONS_HOOK). */
    public function getFormActionsAlignment(): string|Alignment
    {
        return Alignment::End;
    }

    /**
     * Wer das Event nur lesen darf, landet beim Aufruf in der Ansicht statt auf
     * einer 403 – etwa über den Link in der Schadensmeldung. Phase und Bereich
     * bleiben. Nur hier: Bei jeder späteren Anfrage der Seite (hydrate, save)
     * gilt Filaments 403 – sonst speicherte etwa ein Herabgestufter mit noch
     * offenem Tab weiter.
     */
    /**
     * Scheitert das Speichern, steht der Fehler oft in einem anderen Reiter als dem
     * offenen – etwa ein archivierter Veranstalter unter Buchung › Daten, während man
     * unter Buchhaltung „Abrechnung abgeschlossen“ zurücksetzt (21.10.2026). Darum
     * eine Meldung mit Reiter, Feld und Grund.
     */
    protected function onValidationError(ValidationException $exception): void
    {
        $fields = collect($this->form->getFlatFields(withHidden: true))
            ->mapWithKeys(fn (Field $field): array => [$field->getStatePath() => $field]);
        $lines = collect($exception->errors())
            ->map(function (array $messages, string $key) use ($fields): string {
                $field = $fields->get($key);
                $label = $field === null ? Str::after($key, 'data.') : strip_tags((string) $field->getLabel());
                $trail = $field === null ? '' : self::trail($field);

                return ($trail !== '' ? $trail . ' › ' : '') . $label . ': ' . ($messages[0] ?? '');
            })
            ->take(5)
            ->values();

        Notification::make()
            ->danger()
            ->title('Nicht gespeichert')
            ->body(new HtmlString($lines->map(fn (string $line): string => e($line))->implode('<br>')))
            ->persistent()
            ->send();

        parent::onValidationError($exception);
    }

    /** Die Reiter über einem Feld, etwa „Buchung › Daten“ */
    private static function trail(Field $field): string
    {
        $parts = [];
        $component = $field;
        while ($component !== null) {
            $parent = $component->getContainer()->getParentComponent();
            if ($parent instanceof Tab) {
                $label = $parent->getLabel();
                $parts[] = $label instanceof Htmlable ? EventForm::phaseTitle((string) $parent->getId()) : (string) $label;
            }
            $component = $parent;
        }

        return implode(' › ', array_reverse(array_filter($parts)));
    }

    public function mount(int|string $record): void
    {
        if ($this->redirectRenamedSection($record)) {
            return;
        }
        $event = $this->resolveRecord($record);
        if (!EventResource::canEdit($event) && EventResource::canView($event)) {
            $this->record = $event;
            $query = (string) request()->server('QUERY_STRING'); // Reihenfolge wie im Link
            $this->redirect(EventResource::getUrl('view', ['record' => $event]) . ($query ? '?' . $query : ''));

            return;
        }

        parent::mount($record);
    }
}
