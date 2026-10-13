<?php

namespace App\Filament\Pages\Auth;

use App\Models\Calendar;
use App\Models\User;
use App\Support\CalendarView;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * Profil (Benutzermenü › Profil): Jeder ändert hier sein eigenes Passwort, mit
 * dem aktuellen als Bestätigung, und stellt den Kalender ein – Standard-Ansicht
 * und die Adresse des persönlichen Kalenders (eigene Dienste) für Kalender-Apps.
 * Name und E-Mail-Adresse (die Anmeldung) pflegt die Benutzerverwaltung, hier
 * stehen sie nur zur Ansicht.
 *
 * Filament hält danach diese Sitzung angemeldet; andere Geräte meldet
 * AuthenticateSession ab, weil der Passwort-Hash nicht mehr passt.
 */
class EditProfile extends BaseEditProfile
{
    protected Width|string|null $maxContentWidth = Width::ThreeExtraLarge;

    public function form(Schema $schema): Schema
    {
        $user = $this->getUser();

        return $schema->components([
            Section::make('Konto')
                ->description('Name und E-Mail-Adresse ändert die Benutzerverwaltung.')
                ->schema([
                    TextEntry::make('account_name')
                        ->label('Name')
                        ->state($user instanceof User ? $user->getFilamentName() : null),
                    TextEntry::make('account_email')
                        ->label('E-Mail-Adresse')
                        ->state($user->getAttribute('email')),
                ]),
            Section::make('Kalender')
                ->visible(fn (): bool => $this->usesCalendar())
                ->schema([
                    ToggleButtons::make('calendar_view')
                        ->label('Standard-Ansicht')
                        ->helperText('So öffnet sich der Kalender, wenn die Adresse keine Ansicht nennt.')
                        ->options(CalendarView::LABELS)
                        ->inline()
                        ->afterStateHydrated(fn (ToggleButtons $component, ?string $state) => $component->state($state ?: CalendarView::MONTH)),
                    TextEntry::make('personal_feed')
                        ->key('personalFeed')
                        ->label('Persönlicher Kalender zum Abonnieren')
                        ->state(fn (): ?string => $this->usesCalendar() ? route('calendar.personal', ['token' => $this->profileUser()->personalFeedToken()]) : null)
                        ->copyable()
                        ->copyMessage('Adresse kopiert')
                        ->fontFamily(FontFamily::Mono)
                        ->size(TextSize::Small)
                        ->belowContent('Die eigenen Dienste (House Rep., Auf-/Abschließen, VfV) als ICS-Abo für Outlook, Apple oder Google Kalender. Wer die Adresse kennt, sieht diese Termine – im Zweifel eine neue erzeugen.')
                        ->hintAction(
                            Action::make('renewPersonalFeed')
                                ->label('Neue Adresse')
                                ->icon(Heroicon::OutlinedArrowPath)
                                ->requiresConfirmation()
                                ->modalHeading('Neue Adresse erzeugen?')
                                ->modalDescription('Die bisherige Adresse funktioniert danach nicht mehr. Abonnierte Kalender-Apps brauchen die neue.')
                                ->modalSubmitActionLabel('Neue Adresse')
                                ->action(function (): void {
                                    $this->profileUser()->renewPersonalFeedToken();
                                    Notification::make()->success()->title('Neue Adresse erzeugt.')->send();
                                }),
                        ),
                ]),
            Section::make('Passwort ändern')
                ->description('Mindestens 8 Zeichen. Andere Geräte werden danach abgemeldet.')
                ->schema([
                    $this->getPasswordFormComponent(),
                    $this->getPasswordConfirmationFormComponent(),
                    $this->getCurrentPasswordFormComponent(),
                ]),
        ]);
    }

    /**
     * Das aktuelle Passwort nur beim Passwortwechsel: Filament fragt es sonst auch,
     * sobald kein E-Mail-Feld im Formular steht – also bei jedem Speichern.
     */
    protected function getCurrentPasswordFormComponent(): Component
    {
        return parent::getCurrentPasswordFormComponent()
            ->visible(fn (Get $get): bool => filled($get('password')));
    }

    /** Kalender oder Kalender für Externe – beide zeigen „Persönlich“ */
    private function usesCalendar(): bool
    {
        return $this->profileUser()->access()->canCalendar(Calendar::PERSONAL);
    }

    private function profileUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
