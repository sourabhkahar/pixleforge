<?php

namespace App\Providers;

use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pricing\CostCalculator;
use App\Services\Webhooks\PixelforgeSignature;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PixelforgeClient::class, fn () => new PixelforgeClient(
            (string) config('pixelforge.base_url'),
            config('pixelforge.api_key'),
            (int) config('pixelforge.timeout'),
        ));

        $this->app->bind(CostCalculator::class, fn () => new CostCalculator((int) config('pixelforge.markup_percent')));

        $this->app->bind(PixelforgeSignature::class, fn () => new PixelforgeSignature(
            config('pixelforge.webhook_secret'),
            (int) config('pixelforge.webhook_tolerance'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        // App\Listeners\GrantSignupCredits is auto-discovered for the Registered event.
    }
}
