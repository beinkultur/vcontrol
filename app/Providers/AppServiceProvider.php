<?php

namespace App\Providers;

use App\Models\Employee;
use App\Models\Trade;
use App\Models\User;
use App\Support\StageSettings;
use Illuminate\Database\Eloquent\Relations\Relation;
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
        // Kurznamen statt Klassennamen in polymorphen Spalten – dieselben Werte
        // wie assignee_type in der PHP-Version
        Relation::enforceMorphMap([
            'employee' => Employee::class,
            'trade' => Trade::class,
            'user' => User::class,
        ]);
    }
}
