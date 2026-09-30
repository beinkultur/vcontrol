<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Leistungen, die bei einem Event von der Halle oder vom Veranstalter gestellt werden. */
enum ServiceCode: string implements HasLabel
{
    case Vt = 'vt';
    case Sfx = 'sfx';
    case StageSetup = 'stage_setup';
    case StageTeardown = 'stage_teardown';
    case HouseRigIn = 'house_rig_in';
    case HouseRigOut = 'house_rig_out';
    case BarriersSetup = 'barriers_setup';
    case BarriersTeardown = 'barriers_teardown';
    case SmokingSetup = 'smoking_setup';
    case SmokingTeardown = 'smoking_teardown';
    case LockSetup = 'lock_setup';
    case LockTeardown = 'lock_teardown';
    case ChairsSetup = 'chairs_setup';
    case ChairsTeardown = 'chairs_teardown';
    case ProdCrew = 'prod_crew';
    case Security = 'security';
    case FireWatch = 'fire_watch';
    case Sanitary = 'sanitary';
    case CrewCatering = 'crew_catering';

    /**
     * Leistungsbereich der Gewerke, die dafür in Frage kommen (Trade::categories),
     * wie TRADE_SERVICE_CATEGORIES in der PHP-Version. Brandwache hat keinen –
     * dafür steht jedes Gewerk zur Wahl.
     */
    public function tradeCategory(): ?string
    {
        return match ($this) {
            self::Vt, self::HouseRigIn, self::HouseRigOut => 'VA-Technik',
            self::Sfx => 'SFX',
            self::StageSetup, self::StageTeardown, self::BarriersSetup, self::BarriersTeardown,
            self::SmokingSetup, self::SmokingTeardown, self::LockSetup, self::LockTeardown,
            self::ChairsSetup, self::ChairsTeardown => 'Umbau',
            self::ProdCrew => 'Produktion',
            self::Security => 'Sicherheit',
            self::Sanitary => 'Sanitäter',
            self::CrewCatering => 'Catering',
            self::FireWatch => null,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Vt => 'VA-Technik',
            self::Sfx => 'SFX',
            self::StageSetup => 'Bühne Aufbau',
            self::StageTeardown => 'Bühne Abbau',
            self::HouseRigIn => 'Hausrigg Einbau',
            self::HouseRigOut => 'Hausrigg Ausbau',
            self::BarriersSetup => 'Barriers Aufbau',
            self::BarriersTeardown => 'Barriers Abbau',
            self::SmokingSetup => 'Raucherbereich Aufbau',
            self::SmokingTeardown => 'Raucherbereich Abbau',
            self::LockSetup => 'Schleusen Aufbau',
            self::LockTeardown => 'Schleusen Abbau',
            self::ChairsSetup => 'Stühle Aufbau',
            self::ChairsTeardown => 'Stühle Abbau',
            self::ProdCrew => 'Produktionscrew',
            self::Security => 'Security',
            self::FireWatch => 'Brandwache',
            self::Sanitary => 'Sanitätsdienst',
            self::CrewCatering => 'Crewcatering',
        };
    }
}
