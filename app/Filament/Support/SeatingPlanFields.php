<?php

namespace App\Filament\Support;

use App\Access\Area;
use App\Filament\Forms\SafeFileUpload;
use App\Models\Event;
use App\Models\SeatingPlan;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Saalplan (PDF) in Formularen: Standard-Saalpläne der Halle (Stammdaten ›
 * Saalpläne) und je Event entweder einer davon oder ein eigener (Buchung ›
 * Daten, Abschnitt „Halle & Publikum“, für die Buchhaltung auch über „Saalplan
 * ändern“ in der Leseansicht).
 *
 * Wer einen Standard-Saalplan wählt, bekommt dessen Bereiche und Bestuhlung
 * in die Felder „Bereiche“ (areas) und „Bestuhlung“ (seating) – danach lassen
 * sie sich anpassen, etwa wenn eine Tribüne gesperrt ist. Gespeicherte Dateien zeigt das Upload-Feld mit der Adresse
 * über die Rechteprüfung (SeatingPlanController), nicht mit einer öffentlichen.
 */
final class SeatingPlanFields
{
    /** Saalplan eines Events ändern: wer Events bearbeitet oder die Buchhaltung. */
    public static function canChange(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && ($user->access()->canEdit(Area::Events) || $user->access()->canEdit(Area::Buchhaltung));
    }

    /** PDF-Upload, nicht öffentlich, mit „Öffnen“. */
    public static function pdfUpload(string $name): SafeFileUpload
    {
        return SafeFileUpload::make($name)
            ->allowedExtensions(['pdf'])
            ->disk(SeatingPlan::DISK)
            ->visibility('private')
            ->openable();
    }

    /**
     * Felder am Event: Art (Standard oder eigener), dann die Auswahl oder der
     * Upload. Ausgeblendetes wird trotzdem gespeichert (dehydratedWhenHidden),
     * damit der Wechsel der Art das jeweils andere wirklich entfernt; Event::booted
     * sorgt beim Speichern ebenso dafür.
     *
     * @return list<ToggleButtons|Select|SafeFileUpload>
     */
    public static function forEvent(): array
    {
        return [
            ToggleButtons::make('seating_plan_source')
                ->label('Saalplan')
                ->options(['standard' => 'Standard-Saalplan', 'custom' => 'Eigener Saalplan'])
                ->grouped()
                ->default('standard')
                ->dehydrated(false)
                ->live()
                ->afterStateHydrated(fn (ToggleButtons $component, ?Event $record) => $component->state(filled($record?->seating_plan_path) ? 'custom' : 'standard'))
                ->afterStateUpdated(function (?string $state, Set $set): void {
                    if ($state === 'custom') {
                        $set('seating_plan_id', null);
                    } else {
                        $set('seating_plan_path', null);
                    }
                }),
            Select::make('seating_plan_id')
                ->label('Standard-Saalplan')
                ->placeholder('kein Saalplan')
                ->options(fn (?Event $record): array => SeatingPlan::query()
                    ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $record?->seating_plan_id))
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->visible(fn (Get $get): bool => $get('seating_plan_source') !== 'custom')
                ->dehydratedWhenHidden()
                ->live()
                ->afterStateUpdated(function (mixed $state, Set $set): void {
                    $plan = filled($state) ? SeatingPlan::query()->find($state) : null;
                    if ($plan !== null) {
                        $set('areas', array_values(array_map('strval', (array) $plan->areas)));
                        $set('seating', array_values(array_map('strval', (array) $plan->seating)));
                    }
                })
                ->helperText('Bereiche und Bestuhlung des Plans werden übernommen und lassen sich danach anpassen.')
                ->suffixAction(Action::make('openSeatingPlan')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->tooltip('PDF öffnen')
                    ->url(fn (Get $get): ?string => filled($get('seating_plan_id')) ? route('seating-plans.pdf', $get('seating_plan_id')) : null, shouldOpenInNewTab: true)
                    ->visible(fn (Get $get): bool => filled($get('seating_plan_id')))),
            self::pdfUpload('seating_plan_path')
                ->label('Eigener Saalplan (PDF)')
                ->directory(SeatingPlan::DIRECTORY . '/events')
                ->storeFileNamesIn('seating_plan_original_name')
                ->visible(fn (Get $get): bool => $get('seating_plan_source') === 'custom')
                ->dehydratedWhenHidden()
                ->getUploadedFileUsing(fn (string $file, ?Event $record): ?array => self::storedFile(
                    $file,
                    $record?->seating_plan_original_name,
                    $record !== null ? route('events.seating-plan', $record) : null,
                )),
        ];
    }

    /**
     * Bereits gespeicherte Datei im Upload-Feld.
     *
     * @return array{name: string, size: int, type: ?string, url: ?string}|null
     */
    public static function storedFile(string $file, ?string $name, ?string $url): ?array
    {
        $disk = Storage::disk(SeatingPlan::DISK);
        if (!$disk->exists($file)) {
            return null;
        }

        return [
            'name' => filled($name) ? $name : basename($file),
            'size' => $disk->size($file),
            'type' => $disk->mimeType($file) ?: null,
            'url' => $url,
        ];
    }
}
