<?php

namespace App\Providers;

use App\Contracts\InvoiceProvider;
use App\Models\Visit;
use App\Policies\VisitPolicy;
use App\Services\BackupService;
use App\Services\Invoicing\InvoiceProviderFactory;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class DarakServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Every supported provider is an explicit arm in the factory; an unknown
        // value throws on resolution instead of silently becoming the fake.
        $this->app->singleton(InvoiceProvider::class, function () {
            return InvoiceProviderFactory::make(config('darak.invoice_provider', 'fake'));
        });

        // Backups live outside the app directory in production so a bad deploy
        // cannot delete them along with the code.
        $this->app->singleton(BackupService::class, fn () => new BackupService(
            config('darak.backup_path') ?: storage_path('app/backups'),
            config('darak.backup_password'),
            $this->app->environment('production'),
        ));
    }

    public function boot(): void
    {
        Gate::policy(Visit::class, VisitPolicy::class);
    }
}
