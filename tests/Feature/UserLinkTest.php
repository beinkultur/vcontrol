<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Employee;
use App\Models\FieldOption;
use App\Models\Trade;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

/** Benutzer ↔ Mitarbeiter (Personen-Konten, mit Positionen) bzw. Gewerk (Gewerk-Accounts, mehrere je Gewerk). */
class UserLinkTest extends TestCase
{
    private Employee $anna;

    private Trade $trade;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        foreach (['VfV', 'VL'] as $position) {
            FieldOption::create(['field_key' => 'employee_position', 'value' => $position]);
        }
        $this->anna = Employee::create(['first_name' => 'Anna', 'last_name' => 'Koch', 'initials' => 'AK', 'positions' => ['VL']]);
        $this->trade = Trade::create(['short_name' => 'GW', 'name' => 'GraceWork']);
    }

    /** @param  array<string, mixed>  $fields */
    private function create(array $fields): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(CreateUser::class)
            ->fillForm([
                'first_name' => 'Test',
                'last_name' => 'Konto',
                'email' => 'konto' . User::query()->count() . '@example.org',
                'password' => 'geheim-geheim-1',
                'roles' => [$this->role('team' . User::query()->count(), ['kalender' => 'read'])->id],
                'is_active' => true,
                ...$fields,
            ])
            ->call('create');
    }

    public function test_mitarbeiter_der_venue_mit_positionen(): void
    {
        $this->actingAs($this->admin());
        $this->assertSame('Mitarbeiter der Venue', AccountType::VenueEmployee->getLabel());

        $this->create(['account_type' => AccountType::VenueEmployee->value, 'employee_id' => $this->anna->id, 'employee_positions' => ['VfV', 'VL'], 'trade_id' => $this->trade->id])
            ->assertHasNoFormErrors();
        $user = User::query()->where('last_name', 'Konto')->sole();
        $this->assertSame($this->anna->id, $user->employee_id);
        $this->assertNull($user->trade_id, 'die Gewerk-Verknüpfung gehört nicht zum Kontotyp');
        $this->assertEqualsCanonicalizing(['VfV', 'VL'], $this->anna->fresh()->positions);
        $this->assertSame($this->anna->id, $user->linkedEmployeeId());

        // Ein Mitarbeiter, ein Konto
        $this->create(['account_type' => AccountType::VenueEmployee->value, 'employee_id' => $this->anna->id])
            ->assertHasFormErrors(['employee_id']);

        // Kontotyp gewechselt: die Mitarbeiter-Verknüpfung fällt weg
        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm(['account_type' => AccountType::TradeAccount->value, 'trade_id' => $this->trade->id])
            ->call('save')
            ->assertHasNoFormErrors();
        $user->refresh();
        $this->assertNull($user->employee_id);
        $this->assertSame($this->trade->id, $user->linkedTradeId());
        $this->assertEqualsCanonicalizing(['VfV', 'VL'], $this->anna->fresh()->positions, 'die Positionen bleiben beim Mitarbeiter');
    }

    public function test_mehrere_konten_je_gewerk(): void
    {
        $this->actingAs($this->admin());

        foreach (range(1, 3) as $i) {
            $this->create(['account_type' => AccountType::TradeAccount->value, 'trade_id' => $this->trade->id, 'employee_id' => $this->anna->id])
                ->assertHasNoFormErrors();
        }
        $this->assertSame(3, User::query()->where('trade_id', $this->trade->id)->whereNull('employee_id')->count());
        $this->get('/benutzer')->assertOk()->assertSee('Verknüpft mit')->assertSee('GW');
    }

    public function test_positionen_aendert_nur_wer_mitarbeiter_bearbeiten_darf(): void
    {
        $plain = $this->role('leer', []);
        $user = $this->userWith($plain);
        $user->update(['account_type' => AccountType::VenueEmployee, 'employee_id' => $this->anna->id]);

        // Benutzerverwaltung ohne Recht auf Mitarbeiter: Verknüpfung ja, Positionen nein
        $this->actingAs($this->userWith($this->role('personal', ['admin_benutzer' => 'edit'])));
        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->assertFormFieldIsDisabled('employee_positions')
            ->set('data.employee_positions', ['VfV'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(['VL'], $this->anna->fresh()->positions);

        // Mit Recht auf Mitarbeiter
        $this->actingAs($this->userWith($this->role('personal-plus', ['admin_benutzer' => 'edit', 'admin_mitarbeiter' => 'edit'])));
        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->assertSchemaStateSet(['employee_positions' => ['VL']])
            ->fillForm(['employee_positions' => ['VfV']])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(['VfV'], $this->anna->fresh()->positions);
    }
}
