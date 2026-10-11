<?php

namespace Tests\Feature;

use App\Models\BackupRun;
use Tests\TestCase;

class OpsStatusTest extends TestCase
{
    public function test_ohne_richtigen_token_gibt_es_die_route_nicht(): void
    {
        config(['venuecontrol.ops_status_token' => null]);
        $this->get('/_ops/status', ['X-Ops-Token' => ''])->assertNotFound();

        config(['venuecontrol.ops_status_token' => 'geheim']);
        $this->get('/_ops/status')->assertNotFound();
        $this->get('/_ops/status', ['X-Ops-Token' => 'falsch'])->assertNotFound();
    }

    public function test_mit_token_zustand_und_sicherung(): void
    {
        config(['venuecontrol.ops_status_token' => 'geheim']);
        BackupRun::query()->create(['kind' => BackupRun::DATABASE, 'status' => BackupRun::OK, 'trigger' => 'zeitplan', 'started_at' => now()->setTime(3, 30)]);

        $this->get('/_ops/status', ['X-Ops-Token' => 'geheim'])
            ->assertOk()
            ->assertJsonPath('php', PHP_VERSION)
            ->assertJsonPath('laravel', app()->version())
            ->assertJsonPath('env', 'testing')
            ->assertJsonPath('backup.database_ok_at', now()->setTime(3, 30)->format('d.m.Y H:i'))
            ->assertJsonPath('backup.files_ok_at', null)
            ->assertJsonStructure(['commit', 'dirty', 'migrations_pending', 'db_bytes', 'backup' => ['warning']]);
    }
}
