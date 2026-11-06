<?php

namespace App\Providers;

use App\Models\KayakapProfileModel;
use App\Models\ThemeModel;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

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
     * 
     * Detailed Comment: Configures default MySQL string length and registers a global View
     * composer that shares the active clinic theme ($activeTheme) and profile ($activeProfile)
     * across all Blade views defensively, ensuring seamless CSS variable rendering.
     */
    public function boot(): void
    {
        URL::forceRootUrl(config('app.url'));
        Schema::defaultStringLength(191);

        // Detailed Comment: Global View composer injecting $activeTheme and $activeProfile into all Blade views
        View::composer('*', function ($view) {
            $activeTheme = null;
            $activeProfile = null;

            try {
                if (Schema::hasTable('theme')) {
                    $activeTheme = ThemeModel::getActiveTheme();
                }
                if (Schema::hasTable('kayakapmd_profile')) {
                    $activeProfile = KayakapProfileModel::first();
                }
            } catch (\Throwable $e) {
                // Detailed Comment: Suppress exceptions during CLI/migration executions if DB is uninitialized
                $activeTheme = null;
                $activeProfile = null;
            }

            $view->with('activeTheme', $activeTheme);
            $view->with('activeProfile', $activeProfile);
        });
    }
}
