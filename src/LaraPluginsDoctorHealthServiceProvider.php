<?php

namespace LaraPlugins\DoctorHealth;

use Illuminate\Support\ServiceProvider;
use LaraPlugins\DoctorHealth\Diagnostics\PackageHealth;
use Laravel\Doctor\Facades\Doctor;

class LaraPluginsDoctorHealthServiceProvider extends ServiceProvider
{
    /**
     * Register the package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laraplugins-doctor-health.php', 'laraplugins-doctor-health');
    }

    /**
     * Bootstrap the package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            Doctor::diagnostic(PackageHealth::class);

            $this->publishes([
                __DIR__.'/../config/laraplugins-doctor-health.php' => $this->app->configPath('laraplugins-doctor-health.php'),
            ], 'doctor-health-config');
        }
    }
}
