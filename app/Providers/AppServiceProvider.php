<?php

namespace App\Providers;

use App\Models\Employee;
use App\Models\Trade;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
