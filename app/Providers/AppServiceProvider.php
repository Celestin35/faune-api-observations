<?php

namespace App\Providers;

use App\Services\Biodiversity\SourceRegistry;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SourceRegistry::class);
    }

    public function boot(): void
    {
        // Intentionally empty.
    }
}
