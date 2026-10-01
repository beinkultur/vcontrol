<?php

namespace Tests\Feature;

use App\Filament\Pages\AccessCodes;
use App\Models\AccessCode;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class AccessCodesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        foreach (['2026-10-08' => '1111', '2026-10-09' => '2222', '2026-10-10' => '3333'] as $day => $code) {
            AccessCode::create(['code' => $code, 'valid_from' => "{$day} 06:00:00", 'valid_on' => $day]);
        }
        $this->actingAs($this->userWith($this->role('hausmeister', ['codes' => 'read'])));
    }

    public function test_aktueller_code_und_naechste_tage(): void
    {
        $this->travelTo(Carbon::parse('2026-10-09 10:00'));

        Livewire::test(AccessCodes::class)
            ->assertSee('▲2222')
            ->assertSee('Gültig seit 09.10.2026 um 06:00 Uhr')
            ->assertSee('3 Codes hinterlegt (08.10.2026 – 10.10.2026).')
            ->assertSee('▲3333')
            ->assertDontSee('▲1111');

        // Vor 6 Uhr gilt noch der Code vom Vortag
        $this->travelTo(Carbon::parse('2026-10-09 05:30'));
        Livewire::test(AccessCodes::class)->assertSee('Gültig seit 08.10.2026 um 06:00 Uhr');
    }

    public function test_code_nach_datum_auch_per_link(): void
    {
        $this->travelTo(Carbon::parse('2026-10-09 10:00'));

        Livewire::test(AccessCodes::class)
            ->set('datum', '2026-10-08')
            ->assertSee('08.10.2026: ')
            ->assertSee('▲1111')
            ->set('datum', '2026-12-24')
            ->assertSee('Für 24.12.2026 ist kein Code hinterlegt.');

        Livewire::withQueryParams(['datum' => '2026-10-10'])
            ->test(AccessCodes::class)
            ->assertSee('10.10.2026: ');
    }

    public function test_nur_mit_recht_codes(): void
    {
        $this->get('/codes')->assertOk();

        $this->actingAs($this->userWith($this->role('buchhaltung', ['events' => 'read', 'buchhaltung' => 'edit'])));
        $this->get('/codes')->assertForbidden();
    }
}
