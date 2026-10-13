<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Die Mitarbeiter der Stammdaten entfallen (Vorgabe vom 14.10.2026): Positionen,
 * Kürzel und Telefon stehen am Benutzerkonto (Mitarbeiter der Venue,
 * Freelancer); wer kein Konto hat, wird ein Kontakt – eine Person oder eine
 * Funktion wie „Hallenmeister“, ohne Anmeldung, mit Kontaktdaten fürs Daysheet.
 *
 * Übernahme je Mitarbeiter: Gehört er eindeutig zu einem Personen-Konto
 * (verknüpft über users.employee_id, sonst gleiche E-Mail-Adresse, sonst
 * gleicher Name), geht er samt Diensten, Positionen, Kürzel und Telefon in
 * dieses Konto auf; alle übrigen werden Kontakte mit ihren Diensten. plan()
 * zeigt das vorher, ohne etwas zu ändern. Rückwärts werden Kontakte wieder
 * Mitarbeiter; in Konten Aufgegangenes bleibt beim Konto.
 */
return new class extends Migration
{
    private const PERSON_TYPES = [null, 'venue_employee', 'freelancer'];

    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            // Person („Gasparino Duarte“) oder Funktion („Hallenmeister“)
            $table->string('name', 160);
            $table->string('initials', 20)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            // Positionen aus der Feldoption employee_position, z. B. ["VL", "VfV"]
            $table->json('positions')->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('initials', 20)->nullable()->after('last_name');
            $table->string('phone', 50)->nullable()->after('email');
            $table->json('positions')->nullable()->after('account_type');
        });

        $now = now();
        $merged = [];
        foreach ($this->plan() as ['employee' => $employee, 'user' => $user]) {
            if ($user !== null && !isset($merged[$user->id])) {
                $merged[$user->id] = true;
                $positions = array_values(array_unique([...self::list($user->positions ?? null), ...self::list($employee->positions)]));
                DB::table('users')->where('id', $user->id)->update([
                    'positions' => $positions === [] ? null : json_encode($positions, JSON_UNESCAPED_UNICODE),
                    'initials' => $user->initials ?? $employee->initials,
                    'phone' => $user->phone ?? $employee->phone,
                ]);
                $this->reassign((int) $employee->id, 'user', (int) $user->id);

                continue;
            }

            $positions = self::list($employee->positions);
            $contactId = DB::table('contacts')->insertGetId([
                'name' => trim($employee->first_name . ' ' . $employee->last_name) ?: ($employee->initials ?: 'Kontakt ' . $employee->id),
                'initials' => $employee->initials,
                'phone' => $employee->phone,
                'email' => $employee->email,
                'positions' => $positions === [] ? null : json_encode($positions, JSON_UNESCAPED_UNICODE),
                'is_archived' => (bool) ($employee->is_archived ?? false),
                'created_at' => $employee->created_at ?? $now,
                'updated_at' => $now,
            ]);
            $this->reassign((int) $employee->id, 'contact', $contactId);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->dropIndex(['employee_id']);
            $table->dropColumn('employee_id');
        });
        Schema::drop('employees');
    }

    /**
     * Was mit jedem Mitarbeiter geschieht: das Konto, in dem er aufgeht, oder null
     * (wird Kontakt). Ein Konto nimmt höchstens einen Mitarbeiter auf.
     *
     * @return list<array{employee: object, user: ?object}>
     */
    public function plan(): array
    {
        $users = DB::table('users')->get();
        $persons = $users->filter(fn (object $user): bool => in_array($user->account_type, self::PERSON_TYPES, true));
        $normalize = fn (?string $value): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $value)));

        $plan = [];
        $taken = [];
        foreach (DB::table('employees')->orderBy('id')->get() as $employee) {
            $name = $normalize($employee->first_name . ' ' . $employee->last_name);
            $email = $normalize($employee->email);
            $linked = $persons->firstWhere('employee_id', $employee->id);
            $byEmail = $email === '' ? collect() : $persons->filter(fn (object $user): bool => $normalize($user->email) === $email);
            $byName = $persons->filter(fn (object $user): bool => $normalize($user->first_name . ' ' . $user->last_name) === $name);
            $user = match (true) {
                $linked !== null => $linked,
                $byEmail->count() === 1 => $byEmail->first(),
                $byName->count() === 1 => $byName->first(),
                default => null,
            };
            if ($user !== null && isset($taken[$user->id])) {
                $user = null;
            }
            if ($user !== null) {
                $taken[$user->id] = true;
            }
            $plan[] = ['employee' => $employee, 'user' => $user];
        }

        return $plan;
    }

    public function down(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 120)->nullable();
            $table->string('last_name', 120);
            $table->string('initials', 20)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->json('positions')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamps();
        });

        foreach (DB::table('contacts')->orderBy('id')->get() as $contact) {
            $employeeId = DB::table('employees')->insertGetId([
                'last_name' => mb_substr((string) $contact->name, 0, 120),
                'initials' => $contact->initials,
                'phone' => $contact->phone,
                'email' => $contact->email,
                'positions' => $contact->positions,
                'is_archived' => $contact->is_archived,
                'created_at' => $contact->created_at,
                'updated_at' => $contact->updated_at,
            ]);
            DB::table('event_assignments')->where('assignee_type', 'contact')->where('assignee_id', $contact->id)
                ->update(['assignee_type' => 'employee', 'assignee_id' => $employeeId]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_id')->nullable()->index()->after('account_type');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('employee_id')->references('id')->on('employees')->nullOnDelete();
            $table->dropColumn(['initials', 'phone', 'positions']);
        });
        Schema::drop('contacts');
    }

    private function reassign(int $employeeId, string $type, int $id): void
    {
        DB::table('event_assignments')->where('assignee_type', 'employee')->where('assignee_id', $employeeId)
            ->update(['assignee_type' => $type, 'assignee_id' => $id]);
    }

    /** @return list<string> */
    private static function list(mixed $json): array
    {
        $values = is_string($json) && $json !== '' ? json_decode($json, true) : null;

        return is_array($values) ? array_values(array_filter(array_map(fn (mixed $value): string => trim((string) $value), $values), fn (string $value): bool => $value !== '')) : [];
    }
};
