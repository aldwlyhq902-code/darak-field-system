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
            $company = $user?->operatingBranch?->company;

            if ($company === null && $user?->canPanel('admin')) {
                $company = OperatingCompany::query()->where('is_active', true)->oldest('id')->first();
            }

            $view->with('panelCompany', $company);
        });
    }
}
