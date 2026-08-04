<?php

namespace LaraPlugins\DoctorHealth\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use LaraPlugins\DoctorHealth\LaraPluginsDoctorHealthServiceProvider;
use Laravel\Doctor\DoctorServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Get the package providers.
     *
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            DoctorServiceProvider::class,
            LaraPluginsDoctorHealthServiceProvider::class,
        ];
    }

    /**
     * Point the diagnostic at fixture composer files for the duration of the test.
     *
     * @param  string  $fixture  fixture directory relative to tests/Fixtures
     */
    protected function useComposerFixture(string $fixture = ''): void
    {
        $base = __DIR__.'/Fixtures/'.($fixture !== '' ? $fixture.'/' : '');
        $app = $this->app->basePath();

        File::copy($base.'composer.json', $app.'/composer.json');

        if (is_file($base.'composer.lock')) {
            File::copy($base.'composer.lock', $app.'/composer.lock');
        }
    }

    protected function tearDown(): void
    {
        File::delete($this->app->basePath().'/composer.json');
        File::delete($this->app->basePath().'/composer.lock');

        parent::tearDown();
    }
}
