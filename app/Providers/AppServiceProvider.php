<?php

namespace App\Providers;

use App\Components\Claude\Beta\BetaHeaderRegistry;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BetaHeaderRegistry::class, fn () => new BetaHeaderRegistry(
            config('llm.claude.beta_headers')
        ));
    }

    public function boot(): void
    {
        //
    }
}
