<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Explicit bindings required: Laravel's container returns a
        // constructor parameter's default value (null) without even
        // attempting resolution when the parameter has a default AND the
        // class has no explicit binding (see Container::resolveClass()).
        // VisitorKioskService's `?VisitorSheetWriter $sheetWriter = null`
        // would otherwise always resolve to null, silently skipping every
        // Google Sheets write with no exception and no log line.
        $this->app->singleton(\App\Services\GoogleSheets\GoogleSheetsClient::class);
        $this->app->singleton(\App\Services\GoogleSheets\VisitorSheetWriter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
