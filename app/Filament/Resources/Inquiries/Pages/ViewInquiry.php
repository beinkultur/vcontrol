<?php

namespace App\Filament\Resources\Inquiries\Pages;

use App\Filament\Concerns\BoxedPage;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Inquiries\InquiryResource;
use App\Models\Inquiry;
use App\Support\EventDisplay;
use App\Support\Inquiries;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/** Anfrage mit dem Stand jedes Wunschtermins; bestätigen (wird ein Event) oder zurückziehen. */
class ViewInquiry extends ViewRecord
{
    use BoxedPage;

    protected static string $resource = InquiryResource::class;

    public function getHeading(): string
    {
        return (string) $this->getRecord()->getAttribute('title');
    }

    public function getSubheading(): ?string
    {
        /** @var Inquiry $inquiry */
        $inquiry = $this->getRecord();

        return collect([$inquiry->promoter?->name, Inquiry::statusLabel($inquiry->status)])->filter()->implode(' · ');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('confirmInquiry')
                ->label('Bestätigen')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->authorize(fn (Inquiry $record): bool => Gate::allows('confirm', $record))
                ->modalHeading('Termin bestätigen')
                ->modalDescription('Aus dem gewählten Termin wird ein bestätigtes Event mit den Eckdaten der Anfrage; danach geht es ins Event.')
                ->modalSubmitActionLabel('Bestätigen')
                ->schema(fn (Inquiry $record): array => [
                    Radio::make('date_id')
                        ->label('Termin')
                        ->options(self::dateOptions($record))
                        ->disableOptionWhen(fn (string $value): bool => !(Inquiries::slots($record)[(int) $value]['available'] ?? false))
                        ->required(),
                ])
                ->action(function (array $data, Inquiry $record): void {
                    $date = $record->dates->firstWhere('id', (int) $data['date_id']);
                    if ($date === null) {
                        return;
                    }
                    try {
                        $event = Inquiries::confirm($record, $date);
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }
                    Notification::make()->success()->title('Bestätigt – das Event ist angelegt.')->send();
                    $this->redirect(EventResource::getUrl('edit', ['record' => $event]));
                }),
            Action::make('withdrawInquiry')
                ->label('Zurückziehen')
                ->icon(Heroicon::OutlinedArchiveBoxXMark)
                ->color('gray')
                ->authorize(fn (Inquiry $record): bool => Gate::allows('withdraw', $record))
                ->requiresConfirmation()
                ->modalHeading('Anfrage zurückziehen')
                ->modalDescription('Die Anfrage bleibt im Archiv, ihre Termine geben die Optionen frei.')
                ->modalSubmitActionLabel('Zurückziehen')
                ->action(function (Inquiry $record): void {
                    Inquiries::withdraw($record);
                    Notification::make()->success()->title('Zurückgezogen.')->send();
                    $this->refreshFormData(['status']);
                }),
            EditAction::make(),
        ];
    }

    /** @return array<int, string> InquiryDate-ID => „Sa 14.11.2026 · 1️⃣ Option“ */
    private static function dateOptions(Inquiry $inquiry): array
    {
        $slots = Inquiries::slots($inquiry);

        return $inquiry->dates->mapWithKeys(fn ($date): array => [
            $date->id => EventDisplay::weekday($date->option_date) . ' ' . $date->option_date->format('d.m.Y') . ' · ' . ($slots[$date->id]['label'] ?? '–'),
        ])->all();
    }
}
