<?php

namespace App\Console\Commands;

use App\Enums\AccountType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Macht ein Konto zum System-Admin (Betreiber von VenueControl). Der erste
 * System-Admin einer Halle entsteht nur hier, über die Server-Konsole – in der
 * Oberfläche vergeben die Rolle nur System-Admins. Die Rolle legt der Befehl
 * beim ersten Mal an.
 *
 * Gibt es das Konto noch nicht (neue Installation ohne Import), legt der Befehl
 * es auf Nachfrage an: Name und Passwort tippt, wer ihn aufruft, das Passwort
 * verdeckt – es steht nirgends im Klartext, auch nicht in der Shell-Historie.
 */
class GrantSystemAdmin extends Command
{
    protected $signature = 'vc:system-admin {email : E-Mail-Adresse des Kontos}';

    protected $description = 'Macht ein Konto zum System-Admin, legt es bei Bedarf an';

    /** Wie im Benutzerformular (UserForm) */
    private const MIN_PASSWORD = 8;

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));
        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            $user = $this->create(mb_strtolower($email));
            if ($user === null) {
                return self::FAILURE;
            }
        }

        $role = Role::systemAdmin();
        if ($user->roles()->whereKey($role->getKey())->exists()) {
            $this->info($user->getFilamentName() . ' ist schon System-Admin.');

            return self::SUCCESS;
        }

        $user->roles()->attach($role->getKey());
        $this->info($user->getFilamentName() . ' ist jetzt System-Admin.');
        if (!$user->is_active) {
            $this->warn('Das Konto ist inaktiv – anmelden geht erst, wenn es wieder aktiv ist.');
        }

        return self::SUCCESS;
    }

    private function create(string $email): ?User
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Keine gültige E-Mail-Adresse.');

            return null;
        }
        if (!$this->input->isInteractive() || !$this->confirm("Kein Konto mit {$email}. Anlegen?", true)) {
            $this->error('Kein Konto mit dieser E-Mail-Adresse.');

            return null;
        }

        $firstName = trim((string) $this->ask('Vorname'));
        $lastName = trim((string) $this->ask('Nachname'));
        $password = (string) $this->secret('Passwort (mindestens ' . self::MIN_PASSWORD . ' Zeichen)');
        if ($firstName === '' || $lastName === '') {
            $this->error('Vor- und Nachname fehlen.');

            return null;
        }
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            $this->error('Das Passwort ist zu kurz.');

            return null;
        }
        if ($password !== (string) $this->secret('Passwort wiederholen')) {
            $this->error('Die Passwörter stimmen nicht überein.');

            return null;
        }

        return User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'password' => $password,
            'account_type' => AccountType::VenueEmployee,
            'is_active' => true,
        ]);
    }
}
