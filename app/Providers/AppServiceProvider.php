<?php

namespace App\Providers;

use App\Services\AiOrchestrator;
use App\Services\DeepSeekService;
use App\Services\GoogleSheetService;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Stateless services; a single instance per request is sufficient.
        $this->app->singleton(GoogleSheetService::class);
        $this->app->singleton(DeepSeekService::class);
        $this->app->singleton(AiOrchestrator::class);
    }

    public function boot(): void
    {
        // Koyeb terminates TLS in front of the container, so generated URLs and
        // redirects must use https even though PHP-FPM itself sees plain HTTP.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
