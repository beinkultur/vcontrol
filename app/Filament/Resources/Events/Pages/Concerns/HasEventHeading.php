<?php

namespace App\Filament\Resources\Events\Pages\Concerns;

use App\Access\Module;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Schemas\EventForm;
use App\Filament\Resources\ExternEvents\ExternEventResource;
use App\Support\EventDisplay;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;

/**
 * Kompakter Kopf des Workspace in einer Zeile: links der Titel, rechts
 * Datum · Veranstalter · VA-ID · Status und die Aktionen (Bühnenplan …).
 */
trait HasEventHeading
{
    public function getHeading(): string
    {
        return (string) $this->getRecord()->getAttribute('title');
    }

    public function getHeader(): ?View
    {
        return view('filament.events.header', [
            'heading' => $this->getHeading(),
            'meta' => EventDisplay::meta($this->getRecord()),
            'actions' => $this->getCachedHeaderActions(),
        ]);
    }

    /**
     * Alte Links auf einen umbenannten Bereich (?bereich=personal, seit
     * 19.10.2026 „kontakte“ – etwa aus abonnierten Kalendern) landen im neuen.
     * Nur für, wer das Event sehen darf; die Reihenfolge der Adresse bleibt.
     */
    protected function redirectRenamedSection(int|string $record): bool
    {
        $section = request()->query('bereich');
        if (!is_string($section) || !isset(EventForm::RENAMED_SECTIONS[$section])) {
            return false;
        }
        $event = $this->resolveRecord($record);
        if (!EventResource::canView($event)) {
            return false;
        }

        $this->record = $event;
        $query = (string) preg_replace(
            '/(^|&)bereich=' . preg_quote($section, '/') . '(?=&|$)/',
            '$1bereich=' . EventForm::RENAMED_SECTIONS[$section],
            (string) request()->server('QUERY_STRING'),
        );
        $this->redirect(static::getUrl(['record' => $event]) . '?' . $query);

        return true;
    }

    /** Änderungsprotokoll dieses Events, für alle mit Recht „Audit“ */
    protected function historyAction(): Action
    {
        return Action::make('history')
            ->label('Verlauf')
            ->icon(Heroicon::OutlinedClock)
            ->color('gray')
            ->visible(fn (): bool => AuditLogResource::canViewAny())
            ->url(fn (): string => AuditLogResource::getUrl('index', ['filters' => ['event' => ['value' => $this->getRecord()->getKey()]]]));
    }

    /** So sehen beteiligte Freelancer und Gewerke dieses Event (Extern-Bereich, Daysheet). */
    protected function externViewAction(): Action
    {
        return Action::make('externView')
            ->label('Ansicht Extern')
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->url(fn (): string => ExternEventResource::getUrl('view', ['record' => $this->getRecord()]), shouldOpenInNewTab: true);
    }

    protected function stagePlanAction(): Action
    {
        return Action::make('stagePlan')
            ->label('Bühnenplan')
            ->icon(Heroicon::OutlinedMap)
            ->color('gray')
            ->visible(fn (): bool => Module::StagePlan->isEnabled())
            ->url(fn (): string => EventResource::getUrl('stage-plan', ['record' => $this->getRecord()]));
    }
}
