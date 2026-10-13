<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\FieldOption;
use App\Models\Trade;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Personen-Konten (Mitarbeiter der Venue, Freelancer) tragen Positionen, Kürzel
 * und Telefon selbst; Gewerk-Accounts sind mit einem Gewerk verknüpft, mehrere
 * je Gewerk.
 */
class UserPositionsTest extends TestCase
{
    private Trade $trade;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        foreach (['VfV', 'VL'] as $position) {
            FieldOption::create(['field_key' => 'employee_position', 'value' => $position]);
        }
        $this->trade = Trade::create(['short_name' => 'GW', 'name' => 'GraceWork']);
    }

    /** @param  array<string, mixed>  $fields */
    private function create(array $fields): Testable
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

    public function test_mitarbeiter_der_venue_mit_positionen_kuerzel_und_telefon(): void
    {
        $this->actingAs($this->admin());
        $this->assertSame('Mitarbeiter der Venue', AccountType::VenueEmployee->getLabel());

        $this->create(['account_type' => AccountType::VenueEmployee->value, 'positions' => ['VL', 'VfV'], 'initials' => 'TK', 'phone' => '040 123 45', 'trade_id' => $this->trade->id])
            ->assertHasNoFormErrors();
        $user = User::query()->where('last_name', 'Konto')->sole();
        $this->assertEqualsCanonicalizing(['VL', 'VfV'], $user->positionList());
        $this->assertSame('TK', $user->initials);
        $this->assertSame('040 123 45', $user->phone);
        $this->assertNull($user->trade_id, 'das Gewerk gehört nicht zum Kontotyp');
        $this->get('/benutzer')->assertOk()->assertSee('Positionen / Gewerk')->assertSee('VL, VfV');

        // Kontotyp gewechselt: Positionen fallen weg, das Gewerk zählt
        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm(['account_type' => AccountType::TradeAccount->value, 'trade_id' => $this->trade->id])
            ->call('save')
            ->assertHasNoFormErrors();
        $user->refresh();
        $this->assertNull($user->positions);
        $this->assertSame([], $user->positionList());
        $this->assertSame($this->trade->id, $user->linkedTradeId());
    }

    public function test_freelancer_mit_positionen(): void
    {
        $this->actingAs($this->admin());

        $this->create(['account_type' => AccountType::Freelancer->value, 'positions' => ['VfV']])->assertHasNoFormErrors();
        $this->assertSame(['VfV'], User::query()->where('last_name', 'Konto')->sole()->positionList());
    }

    public function test_mehrere_konten_je_gewerk(): void
    {
        $this->actingAs($this->admin());

        foreach (range(1, 3) as $i) {
            $this->create(['account_type' => AccountType::TradeAccount->value, 'trade_id' => $this->trade->id, 'positions' => ['VL']])
                ->assertHasNoFormErrors();
        }
        $this->assertSame(3, User::query()->where('trade_id', $this->trade->id)->whereNull('positions')->count());
        $this->get('/benutzer')->assertOk()->assertSee('GW');
    }

    public function test_positionen_vergibt_die_benutzerverwaltung(): void
    {
        $user = $this->userWith($this->role('leer', []));
        $user->update(['account_type' => AccountType::VenueEmployee]);

        $this->actingAs($this->userWith($this->role('personal', ['admin_benutzer' => 'edit'])));
        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm(['positions' => ['VL']])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(['VL'], $user->fresh()->positionList());
    }
}
