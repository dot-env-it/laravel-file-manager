<?php

namespace DotEnvIt\FileManager;

use DotEnvIt\FileManager\Livewire\FileManager;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class FileManagerServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Merge the config file so the app can use config('file-manager')
        $this->mergeConfigFrom(
            __DIR__ . '/../config/file-manager.php',
            'file-manager'
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 1. Load Views with a namespace: file-manager::view-name
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'file-manager');

        $this->loadTranslationsFrom(__DIR__ . '/../lang', 'file-manager');

        // 2. Register the Livewire Component
        Livewire::component('file-manager', FileManager::class);

        // 3. Allow users to publish the config and views
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/file-manager.php' => config_path('file-manager.php'),
            ], 'file-manager-config');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/file-manager'),
            ], 'file-manager-views');

            $this->publishes([
                __DIR__ . '/../lang' => resource_path('lang/vendor/file-manager'),
            ], 'file-manager-translations');
        }
    }
}
