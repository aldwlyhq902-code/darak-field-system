<?php

namespace App\Providers;

use App\Contracts\InvoiceProvider;
use App\Models\Visit;
use App\Policies\VisitPolicy;
use App\Services\BackupService;
use App\Services\Invoicing\FakeInvoiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class DarakServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Swapping to the real e-invoicing provider is a one-line change here.
        $this->app->singleton(InvoiceProvider::class, function () {
            return match (config('darak.invoice_provider', 'fake')) {
                default => new FakeInvoiceProvider(),
            };
        });

        // Backups live outside the app directory in production so a bad deploy
        // cannot delete them along with the code.
        $this->app->singleton(BackupService::class, fn () => new BackupService(
            config('darak.backup_path') ?: storage_path('app/backups'),
        ));
    }

    public function boot(): void
    {
        Gate::policy(Visit::class, VisitPolicy::class);
    }
}
