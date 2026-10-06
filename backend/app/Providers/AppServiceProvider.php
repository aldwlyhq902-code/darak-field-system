<?php

namespace App\Providers;

use App\Models\OperatingCompany;
use Illuminate\Support\Facades\View;
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
        View::composer(['layouts.panel', 'layouts.sales'], function ($view): void {
            $user = auth('web')->user();
            // A company-wide user (no branch) is branded with its own company,
            // never with whichever tenant happens to be the oldest row.
            $company = $user?->operatingBranch?->company ?? $user?->operatingCompany;

            if ($company === null && $user?->isPlatformAdmin()) {
                $company = OperatingCompany::query()->where('is_active', true)->oldest('id')->first();
            }

            $view->with('panelCompany', $company);
        });
    }
}
