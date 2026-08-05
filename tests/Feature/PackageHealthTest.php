<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use LaraPlugins\DoctorHealth\Diagnostics\PackageHealth;
use Laravel\Doctor\Results\Status;

function resolveHealthDiagnostic(): PackageHealth
{
    return app(PackageHealth::class);
}

function healthApiPayload(array $results = [], int $unindexedCount = 0, array $unindexed = []): array
{
    return [
        'results' => $results,
        'unindexed' => $unindexed,
        'checked' => count($results) + $unindexedCount,
        'indexed' => count($results),
        'unindexed_count' => $unindexedCount,
    ];
}

function healthyResult(string $name, string $version = '1.0.0'): array
{
    return [
        'health_status' => 'healthy',
        'latest_version' => $version,
        'last_push' => '2026-07-01T00:00:00+00:00',
        'is_archived' => false,
    ];
}

beforeEach(function () {
    $this->useComposerFixture();
});

it('initializes the diagnostic name and group', function () {
    $diagnostic = resolveHealthDiagnostic();

    expect($diagnostic->name)->toBe('Package health')
        ->and($diagnostic->group)->toBe('laraplugins');
});

it('passes when all indexed dependencies are healthy', function () {
    Http::fake([
        'laraplugins.io/api/v1/packages/health' => Http::response(healthApiPayload([
            'laravel/framework' => healthyResult('laravel/framework', '13.23.0'),
            'spatie/laravel-permission' => healthyResult('spatie/laravel-permission', '6.9.0'),
        ])),
    ]);

    $result = resolveHealthDiagnostic()->check();

    expect($result->status)->toBe(Status::Pass)
        ->and($result->code)->toBe('package-health.passed')
        ->and($result->details)->toContain('laravel/framework 13.23.0 → Healthy (latest 13.23.0)');
});

it('fails when a dependency is unhealthy', function () {
    Http::fake([
        'laraplugins.io/api/v1/packages/health' => Http::response(healthApiPayload([
            'laravel/framework' => healthyResult('laravel/framework', '13.23.0'),
            'spatie/laravel-permission' => [
                'health_status' => 'unhealthy',
                'latest_version' => '6.9.0',
                'last_push' => '2025-01-15T00:00:00+00:00',
                'is_archived' => false,
            ],
        ])),
    ]);

    $result = resolveHealthDiagnostic()->check();

    expect($result->status)->toBe(Status::Fail)
        ->and($result->code)->toBe('package-health.failed');
});

it('warns when a dependency is medium', function () {
    Http::fake([
        'laraplugins.io/api/v1/packages/health' => Http::response(healthApiPayload([
            'spatie/laravel-permission' => [
                'health_status' => 'medium',
                'latest_version' => '6.9.0',
                'last_push' => null,
                'is_archived' => false,
            ],
        ])),
    ]);

    $result = resolveHealthDiagnostic()->check();

    expect($result->status)->toBe(Status::Warn);
});

it('warns for archived packages by default', function () {
    Http::fake([
        'laraplugins.io/api/v1/packages/health' => Http::response(healthApiPayload([
            'vendor/archived' => [
                'health_status' => 'healthy',
                'latest_version' => '1.0.0',
                'last_push' => null,
                'is_archived' => true,
            ],
        ])),
    ]);

    $result = resolveHealthDiagnostic()->check();

    expect($result->status)->toBe(Status::Warn);
});

it('fails for archived packages when archived_verdict is fail', function () {
    config(['laraplugins-doctor-health.archived_verdict' => 'fail']);

    Http::fake([
        'laraplugins.io/api/v1/packages/health' => Http::response(healthApiPayload([
            'vendor/archived' => [
                'health_status' => 'healthy',
                'latest_version' => '1.0.0',
                'last_push' => null,
                'is_archived' => true,
            ],
        ])),
    ]);

    $result = resolveHealthDiagnostic()->check();

    expect($result->status)->toBe(Status::Fail);
});

it('notices when there are unindexed packages but no issues', function () {
    Http::fake([
        'laraplugins.io/api/v1/packages/health' => Http::response(healthApiPayload(
            results: ['laravel/framework' => healthyResult('laravel/framework', '13.23.0')],
            unindexedCount: 1,
            unindexed: ['mystery/package' => ['is_indexed' => false, 'submit_url' => 'https://laraplugins.io/suggest']],
        )),
    ]);

    $result = resolveHealthDiagnostic()->check();

    expect($result->status)->toBe(Status::Notice)
        ->and($result->details)->toContain('mystery/package');
});

it('skips when there are no dependencies', function () {
    Http::fake();

    config(['laraplugins-doctor-health.include_require_dev' => false]);

    File::put($this->app->basePath().'/composer.json', json_encode(['name' => 'acme/empty']));

    $result = resolveHealthDiagnostic()->check();

    expect($result->status)->toBe(Status::Skip);
});

it('skips when the diagnostic is disabled', function () {
    Http::fake();

    config(['laraplugins-doctor-health.enabled' => false]);

    $result = resolveHealthDiagnostic()->check();

    expect($result->status)->toBe(Status::Skip);
});

it('warns when the API is unreachable', function () {
    Http::fake([
        'laraplugins.io/api/v1/packages/health' => Http::response('', 500),
    ]);

    $result = resolveHealthDiagnostic()->check();

    expect($result->status)->toBe(Status::Warn)
        ->and($result->code)->toBe('package-health.unreachable');
});

it('warns when the API returns 422', function () {
    Http::fake([
        'laraplugins.io/api/v1/packages/health' => Http::response('', 422),
    ]);

    $result = resolveHealthDiagnostic()->check();

    expect($result->status)->toBe(Status::Warn)
        ->and($result->code)->toBe('package-health.validation-error');
});

it('warns when the API rate-limits (429)', function () {
    Http::fake([
        'laraplugins.io/api/v1/packages/health' => Http::response('', 429),
    ]);

    $result = resolveHealthDiagnostic()->check();

    expect($result->status)->toBe(Status::Warn)
        ->and($result->code)->toBe('package-health.rate-limited');
});

it('warns when the API times out', function () {
    Http::fake([
        'laraplugins.io/api/v1/packages/health' => Http::response(healthApiPayload()),
    ]);

    Http::fake(function ($request) {
        throw new ConnectionException('timeout');
    });

    $result = resolveHealthDiagnostic()->check();

    expect($result->status)->toBe(Status::Warn);
});

it('sends the expected request payload to the API', function () {
    Http::fake();

    resolveHealthDiagnostic()->check();

    Http::assertSent(function ($request) {
        $payload = $request->data();
        $packages = $payload['packages'] ?? [];

        $names = array_column($packages, 'package');

        return $request->url() === 'https://laraplugins.io/api/v1/packages/health'
            && in_array('laravel/framework', $names, strict: true)
            && in_array('pestphp/pest', $names, strict: true)
            && collect($packages)->firstWhere('package', 'laravel/framework')['version'] === '13.23.0';
    });
});

it('caches the API response across checks', function () {
    Http::fake([
        'laraplugins.io/api/v1/packages/health' => Http::response(healthApiPayload([
            'laravel/framework' => healthyResult('laravel/framework', '13.23.0'),
        ])),
    ]);

    $diagnostic = resolveHealthDiagnostic();
    $diagnostic->check();
    $diagnostic->check();

    Http::assertSentCount(1);
});

it('matches api response keys by lowercase name', function () {
    Http::fake([
        'laraplugins.io/api/v1/packages/health' => Http::response(healthApiPayload([
            'spatie/laravel-permission' => healthyResult('spatie/laravel-permission', '6.9.0'),
        ])),
    ]);

    $result = resolveHealthDiagnostic()->check();

    expect($result->status)->toBe(Status::Pass)
        ->and($result->details)->toContain('spatie/laravel-permission');
});
