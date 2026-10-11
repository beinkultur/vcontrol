<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Macht ein Konto zum System-Admin (Betreiber von VenueControl). Der erste
 * System-Admin einer Halle entsteht nur hier, über die Server-Konsole – in der
 * Oberfläche vergeben die Rolle nur System-Admins. Die Rolle legt der Befehl
 * beim ersten Mal an.
 */
class GrantSystemAdmin extends Command
{
    protected $signature = 'vc:system-admin {email : E-Mail-Adresse des Kontos}';

    protected $description = 'Macht ein Konto zum System-Admin';

    public function handle(): int
    {
        $user = User::query()->where('email', trim((string) $this->argument('email')))->first();
        if ($user === null) {
            $this->error('Kein Konto mit dieser E-Mail-Adresse.');

            return self::FAILURE;
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
}
