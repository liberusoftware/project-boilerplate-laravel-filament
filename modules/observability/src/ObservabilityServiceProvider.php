<?php

namespace Liberu\Foundation\Observability;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Liberu\Foundation\Observability\Contracts\Metrics;
use Liberu\Foundation\Observability\Contracts\ObservabilityActor;
use Liberu\Foundation\Observability\Http\Middleware\CorrelationId;
use Liberu\Foundation\Observability\Providers\HorizonDashboardServiceProvider;
use Liberu\Foundation\Observability\Providers\TelescopeDashboardServiceProvider;
use Liberu\Foundation\Observability\Support\NullMetrics;
use Liberu\Foundation\Observability\Support\SloRegistry;

final class ObservabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(HorizonDashboardServiceProvider::class);
        $this->app->register(TelescopeDashboardServiceProvider::class);
        $this->app->bind(Metrics::class, NullMetrics::class);
        $this->app->singleton(SloRegistry::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->callAfterResolving(HttpKernel::class, function (Kernel $kernel): void {
            $kernel->appendMiddlewareToGroup('web', CorrelationId::class);
            $kernel->appendMiddlewareToGroup('api', CorrelationId::class);
        });
        Gate::define('viewPulse', fn (ObservabilityActor $actor): bool => $actor->isAdmin());
    }
}
