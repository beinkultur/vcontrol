<?php

namespace App\Filament\Resources\Users\Pages\Concerns;

use App\Filament\Resources\Users\Schemas\UserForm;
use App\Models\User;
use App\Support\AccessData;
use Filament\Notifications\Notification;

/**
 * Nach dem Speichern eines Kontos die Zugangsdaten schicken, wenn „per E-Mail
 * senden“ an ist (UserForm::SEND_ACCESS_DATA): nur mit einem Passwort aus
 * diesem Speichern und nur an aktive Konten. Das Passwort im Klartext hält die
 * Seite nur zwischen Speichern und Versand – als private Eigenschaft geht es
 * nicht an den Browser zurück.
 */
trait SendsAccessData
{
    private ?string $plainPassword = null;

    /** @param  array<string, mixed>  $data */
    private function rememberPassword(array $data): void
    {
        $this->plainPassword = filled($data['password'] ?? null) ? (string) $data['password'] : null;
    }

    private function sendAccessDataIfRequested(bool $isNew): void
    {
        /** @var User $user */
        $user = $this->getRecord();
        $password = $this->plainPassword;
        $this->plainPassword = null;
        $requested = (bool) ($this->data[UserForm::SEND_ACCESS_DATA] ?? false);
        // Nicht noch einmal mit demselben Passwort beim nächsten Speichern
        $this->data[UserForm::SEND_ACCESS_DATA] = false;
        $this->data['password'] = null;
        if (!$requested || $password === null || !$user->is_active) {
            return;
        }

        if (AccessData::send($user, $password, $isNew)) {
            Notification::make()->success()->title('Zugangsdaten an ' . $user->email . ' verschickt.')->send();

            return;
        }
        Notification::make()
            ->danger()
            ->persistent()
            ->title('Die Mail an ' . $user->email . ' ging nicht raus.')
            ->body('Das Konto ist gespeichert. Das Passwort bitte auf anderem Weg weitergeben oder später ein neues generieren und senden.')
            ->send();
    }
}
