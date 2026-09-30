<?php

namespace Tests\Unit;

use App\Access\Area;
use App\Access\Level;
use PHPUnit\Framework\TestCase;

class LevelTest extends TestCase
{
    public function test_stufen_sind_geordnet(): void
    {
        $this->assertTrue(Level::Edit->atLeast(Level::Read));
        $this->assertTrue(Level::Read->atLeast(Level::Read));
        $this->assertFalse(Level::Read->atLeast(Level::Edit));
        $this->assertFalse(Level::None->atLeast(Level::Read));
    }

    public function test_hoehere_stufe_gewinnt(): void
    {
        $this->assertSame(Level::Edit, Level::max(Level::Read, Level::Edit));
        $this->assertSame(Level::Read, Level::max(Level::Read, Level::None));
    }

    public function test_unbekannte_gespeicherte_werte_zaehlen_als_kein_zugriff(): void
    {
        $this->assertSame(Level::None, Level::fromStored(null));
        $this->assertSame(Level::None, Level::fromStored('admin'));
        $this->assertSame(Level::Edit, Level::fromStored('edit'));
    }

    public function test_bereichsgruppen_wie_in_der_php_version(): void
    {
        $this->assertSame(Area::GROUP_ADMIN, Area::AdminRollen->group());
        $this->assertSame(Area::GROUP_EXTERN, Area::KalenderExtern->group());
        $this->assertSame(Area::GROUP_MODULE, Area::EventsOperations->group());
        $this->assertCount(24, Area::cases());
    }
}
