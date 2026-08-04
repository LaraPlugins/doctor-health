<?php

use LaraPlugins\DoctorHealth\ComposerDependencies;

it('reads require and require-dev packages with versions from the lock', function () {
    $dependencies = new ComposerDependencies(__DIR__.'/../Fixtures');

    $packages = $dependencies->all();

    expect($packages)->toHaveCount(4)
        ->and($packages[0])->toBe(['name' => 'laravel/framework', 'version' => '13.23.0'])
        ->and($packages[1])->toBe(['name' => 'spatie/laravel-permission', 'version' => '6.9.0'])
        ->and($packages[2])->toBe(['name' => 'pestphp/pest', 'version' => '5.0.3'])
        ->and($packages[3])->toBe(['name' => 'symfony/console', 'version' => '7.3.0']);
});

it('excludes require-dev when disabled', function () {
    $dependencies = new ComposerDependencies(__DIR__.'/../Fixtures');

    $packages = $dependencies->all(includeRequireDev: false);

    expect($packages)->toHaveCount(2)
        ->and(array_column($packages, 'name'))->toContain('laravel/framework')
        ->and(array_column($packages, 'name'))->not->toContain('pestphp/pest');
});

it('excludes platform and tooling packages', function () {
    $dependencies = new ComposerDependencies(__DIR__.'/../Fixtures');

    $packages = $dependencies->all();

    $names = array_column($packages, 'name');

    expect($names)->not->toContain('php')
        ->and($names)->not->toContain('ext-json');
});

it('honors the exclude list', function () {
    $dependencies = new ComposerDependencies(__DIR__.'/../Fixtures');

    $packages = $dependencies->all(exclude: ['laravel/framework']);

    expect(array_column($packages, 'name'))->not->toContain('laravel/framework');
});

it('reports missing composer.json as absent', function () {
    $dependencies = new ComposerDependencies('/path/that/does/not/exist');

    expect($dependencies->exists())->toBeFalse()
        ->and($dependencies->all())->toBe([]);
});

it('reports corrupt composer.json as invalid', function () {
    $dependencies = new ComposerDependencies(__DIR__.'/../Fixtures/corrupt');

    expect($dependencies->exists())->toBeTrue()
        ->and($dependencies->isValid())->toBeFalse();
});
