<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;

/**
 * Profil (Benutzermenü › Profil): Jeder ändert hier sein eigenes Passwort, mit
 * dem aktuellen als Bestätigung. Name und E-Mail-Adresse (die Anmeldung) pflegt
 * die Benutzerverwaltung, hier stehen sie nur zur Ansicht.
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
            Section::make('Passwort ändern')
                ->description('Mindestens 8 Zeichen. Andere Geräte werden danach abgemeldet.')
                ->schema([
                    $this->getPasswordFormComponent(),
                    $this->getPasswordConfirmationFormComponent(),
                    $this->getCurrentPasswordFormComponent(),
                ]),
        ]);
    }
}
