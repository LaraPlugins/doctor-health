<?php

namespace LaraPlugins\DoctorHealth\Diagnostics;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as Http;
use LaraPlugins\DoctorHealth\ComposerDependencies;
use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\Results\DiagnosticResult;
use Laravel\Doctor\Results\Link;
use Laravel\Doctor\Results\Message;
use Laravel\Doctor\Support\Details;

class PackageHealth extends Diagnostic
{
    public string $group = 'laraplugins';

    /**
     * In-process response cache to avoid repeat calls during re-runs.
     *
     * @var array<string, array{
     *     results: array<string, array<string, mixed>>,
     *     unindexed: array<string, array<string, mixed>>,
     *     checked: int,
     *     indexed: int,
     *     unindexed_count: int,
     * }>
     */
    protected array $cache = [];

    /**
     * Create a new package health diagnostic.
     */
    public function __construct(
        protected Application $app,
        protected Http $http,
    ) {
        parent::__construct();
    }

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, string|Message>
     */
    protected function messages(): array
    {
        return [
            'passed' => Message::make(
                summary: 'All indexed dependencies are healthy.',
                remediation: 'Visit laraplugins.io to review the full health report for your packages.',
            )->link(Link::to('Browse plugins', 'https://laraplugins.io/plugins')),
            'unindexed-notice' => Message::make(
                summary: '{unindexed} dependencies are not indexed by LaraPlugins.',
                remediation: 'Submit them on laraplugins.io to get health scores for your full dependency tree.',
            ),
            'warning' => Message::make(
                summary: 'Some dependencies need attention.',
                remediation: 'Review the packages below and visit laraplugins.io for details.',
            )->link(Link::to('Browse plugins', 'https://laraplugins.io/plugins')),
            'failed' => Message::make(
                summary: 'Unhealthy or archived dependencies found.',
                remediation: 'Review the packages below — they may be abandoned, archived, or have security advisories.',
            )->link(Link::to('Browse plugins', 'https://laraplugins.io/plugins')),
            'unreachable' => Message::make(
                summary: 'Could not reach the LaraPlugins health API.',
                remediation: 'Check your network connection and the configured LARAPLUGINS_DOCTOR_URL.',
            ),
            'validation-error' => Message::make(
                summary: 'The LaraPlugins health API rejected the request (HTTP 422).',
                remediation: 'Check your laraplugins-doctor-health configuration — the payload may be malformed or the package list is too large.',
            ),
            'rate-limited' => Message::make(
                summary: 'The LaraPlugins health API rate-limited this request (HTTP 429).',
                remediation: 'Wait and retry later, or sponsor the project for higher rate limits.',
            ),
            'truncated' => Message::make(
                summary: 'Only the first {limit} of {total} dependencies were checked.',
                remediation: 'Increase laraplugins-doctor-health.package_limit or exclude packages to check more.',
            ),
            'empty' => Message::make(
                summary: 'No composer dependencies found to check.',
                remediation: 'Install some packages first.',
            ),
            'corrupt' => Message::make(
                summary: 'composer.json is not valid JSON.',
                remediation: 'Fix your composer.json so the dependency list can be read.',
            ),
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        if (! $this->isEnabled()) {
            return $this->skip('empty');
        }

        $dependencies = new ComposerDependencies($this->app->basePath());

        if (! $dependencies->exists()) {
            return $this->skip('empty');
        }

        if (! $dependencies->isValid()) {
            return $this->warn('corrupt');
        }

        $limit = (int) config('laraplugins-doctor-health.package_limit', 250);

        $packages = $dependencies->all(
            includeRequireDev: (bool) config('laraplugins-doctor-health.include_require_dev', true),
            exclude: config('laraplugins-doctor-health.exclude_packages', []),
        );

        if ($packages === []) {
            return $this->skip('empty');
        }

        $truncated = count($packages) > $limit;

        if ($truncated) {
            $packages = array_slice($packages, 0, $limit);
        }

        $response = $this->fetch($packages);

        if ($response === null) {
            return $this->unreachableVerdict();
        }

        if (is_int($response)) {
            return $this->httpErrorVerdict($response);
        }

        $results = $response['results'] ?? [];
        $unindexed = $response['unindexed'] ?? [];

        $bullets = $this->buildBullets($packages, $results);

        foreach ($unindexed as $name => $meta) {
            $bullets[] = "{$name} — Not indexed (submit: ".(is_array($meta) ? ($meta['submit_url'] ?? '') : '').')';
        }

        if ($truncated) {
            return $this->warn('truncated', [
                'limit' => $limit,
                'total' => $response['checked'] ?? count($packages),
            ])->withDetails(Details::bullets($bullets));
        }

        $unhealthy = array_filter(
            $results,
            fn (array $info): bool => $info['health_status'] === 'unhealthy',
        );

        $archived = array_filter(
            $results,
            fn (array $info): bool => (bool) ($info['is_archived'] ?? false),
        );

        if ($unhealthy !== [] || ($archived !== [] && $this->archivedVerdict() === 'fail')) {
            return $this->fail('failed')->withDetails(Details::bullets($bullets));
        }

        $medium = array_filter(
            $results,
            fn (array $info): bool => $info['health_status'] === 'medium',
        );

        if ($archived !== [] || $medium !== []) {
            return $this->warn('warning')->withDetails(Details::bullets($bullets));
        }

        if ($response['unindexed_count'] > 0) {
            return $this->notice('unindexed-notice', [
                'unindexed' => $response['unindexed_count'],
            ])->withDetails(Details::bullets($bullets));
        }

        return $this->pass('passed')->withDetails(Details::bullets($bullets));
    }

    /**
     * Fetch the health report from the LaraPlugins API.
     *
     * Returns the parsed response array on success, an int HTTP status for
     * client errors (422, 429), or null for network/timeout/5xx.
     *
     * @param  array<int, array{name: string, version: string|null}>  $packages
     * @return array<string, mixed>|int|null
     */
    protected function fetch(array $packages): array|int|null
    {
        $names = array_map(fn (array $package): string => $package['name'], $packages);

        sort($names);

        $cacheKey = sha1(
            implode(',', $names)
            .'|'.config('laraplugins-doctor-health.url')
            .'|'.config('laraplugins-doctor-health.package_limit'),
        );

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $payload = array_map(
            fn (array $p): array => [
                'package' => $p['name'],
                'version' => $p['version'] ?? null,
            ],
            $packages,
        );

        $url = rtrim((string) config('laraplugins-doctor-health.url', 'https://laraplugins.io/api'), '/');
        $retry = config('laraplugins-doctor-health.retry', ['times' => 2, 'sleep' => 100]);

        try {
            $response = $this->http
                ->withHeaders(config('laraplugins-doctor-health.http_headers', []))
                ->acceptJson()
                ->timeout((int) config('laraplugins-doctor-health.timeout', 5))
                ->connectTimeout((int) config('laraplugins-doctor-health.connect_timeout', 5))
                ->retry((int) $retry['times'], (int) $retry['sleep'], throw: false)
                ->post($url.'/v1/packages/health', ['packages' => $payload]);
        } catch (\Throwable $e) {
            return null;
        }

        if ($response->failed()) {
            $status = $response->status();

            if (in_array($status, [422, 429], strict: true)) {
                return $status;
            }

            return null;
        }

        $data = $response->json();

        if (! is_array($data)) {
            return null;
        }

        return $this->cache[$cacheKey] = $this->normalizePayload($data);
    }

    /**
     * Normalize a raw API response into the cached payload shape.
     *
     * @param  array<mixed>  $data
     * @return array{
     *     results: array<string, array<string, mixed>>,
     *     unindexed: array<string, array<string, mixed>>,
     *     checked: int,
     *     indexed: int,
     *     unindexed_count: int,
     * }
     */
    protected function normalizePayload(array $data): array
    {
        return [
            'results' => is_array($data['results'] ?? null) ? $data['results'] : [],
            'unindexed' => is_array($data['unindexed'] ?? null) ? $data['unindexed'] : [],
            'checked' => (int) ($data['checked'] ?? 0),
            'indexed' => (int) ($data['indexed'] ?? 0),
            'unindexed_count' => (int) ($data['unindexed_count'] ?? 0),
        ];
    }

    /**
     * Build the per-package detail lines.
     *
     * @param  array<int, array{name: string, version: string|null}>  $packages
     * @param  array<string, array<string, mixed>>  $results
     * @return list<string>
     */
    protected function buildBullets(array $packages, array $results): array
    {
        $bullets = [];

        foreach ($packages as $package) {
            $nameLower = strtolower($package['name']);
            $info = $results[$nameLower] ?? null;

            if ($info === null) {
                continue;
            }

            $status = strtolower((string) ($info['health_status'] ?? 'unrated'));
            $latest = $info['latest_version'] ?? null;

            $label = match ($status) {
                'healthy' => 'Healthy',
                'medium' => 'Medium',
                'unhealthy' => 'Unhealthy',
                'unrated' => 'Unrated',
                default => 'Unknown',
            };

            if (($info['is_archived'] ?? false) && $status !== 'unhealthy') {
                $label .= ' (archived)';
            }

            $version = $package['version'] ?? null;
            $versionText = $version !== null ? " {$version}" : ' (version unknown)';
            $latestText = $latest !== null ? " (latest {$latest})" : '';

            $bullets[] = "{$package['name']}{$versionText} → {$label}{$latestText}";
        }

        return $bullets;
    }

    protected function isEnabled(): bool
    {
        return (bool) config('laraplugins-doctor-health.enabled', true);
    }

    protected function unreachableVerdict(): DiagnosticResult
    {
        $verdict = (string) config('laraplugins-doctor-health.unreachable_verdict', 'warn');

        return match ($verdict) {
            'skip' => $this->skip('unreachable'),
            'error' => $this->error('unreachable'),
            default => $this->warn('unreachable'),
        };
    }

    protected function httpErrorVerdict(int $status): DiagnosticResult
    {
        $verdict = (string) config('laraplugins-doctor-health.unreachable_verdict', 'warn');

        $message = $status === 429 ? 'rate-limited' : 'validation-error';

        return match ($verdict) {
            'skip' => $this->skip($message),
            'error' => $this->error($message),
            default => $this->warn($message),
        };
    }

    protected function archivedVerdict(): string
    {
        return (string) config('laraplugins-doctor-health.archived_verdict', 'warn');
    }
}
