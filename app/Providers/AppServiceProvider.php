<?php

namespace App\Providers;

use App\Models\Employee;
use App\Models\Trade;
use App\Models\User;
use App\Support\Audit;
use App\Support\StageSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event as Events;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bühnen-Stammdaten der Halle: einmal je Anfrage aus der Datenbank
        $this->app->scoped(StageSettings::class, fn (): StageSettings => StageSettings::load());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Änderungsprotokoll: jede Änderung über Eloquent (App\Support\Audit)
        foreach ([Audit::CREATED, Audit::UPDATED, Audit::DELETED] as $action) {
            Events::listen("eloquent.{$action}: *", function (string $event, array $payload) use ($action): void {
                if (($payload[0] ?? null) instanceof Model) {
                    Audit::model($action, $payload[0]);
                }
            });
        }

        // Kurznamen statt Klassennamen in polymorphen Spalten – dieselben Werte
        // wie assignee_type in der PHP-Version
        Relation::enforceMorphMap([
            'employee' => Employee::class,
            'trade' => Trade::class,
            'user' => User::class,
        ]);
    }
}
