<?php

namespace LaraPlugins\DoctorHealth;

use Composer\InstalledVersions;
use Illuminate\Support\Str;

class ComposerDependencies
{
    /**
     * Platform and tooling packages that carry no health signal.
     *
     * @var array<int, string>
     */
    protected const array PLATFORM_PACKAGES = [
        'php',
        'composer',
        'composer-plugin-api',
        'composer-runtime-api',
        'composer/ca-bundle',
        'composer/semver',
        'composer/xdebug-handler',
    ];

    /**
     * Create a new composer dependencies reader.
     */
    public function __construct(
        protected string $basePath,
    ) {}

    /**
     * Determine whether the application has a composer.json file.
     */
    public function exists(): bool
    {
        return is_file($this->composerJsonPath());
    }

    /**
     * Determine whether composer.json is valid JSON.
     */
    public function isValid(): bool
    {
        return json_decode((string) file_get_contents($this->composerJsonPath()), true) !== null;
    }

    /**
     * Get the dependency list as name/version pairs, normalized and deduplicated.
     *
     * @param  array<int, string>  $exclude
     * @return array<int, array{name: string, version: string|null}>
     */
    public function all(bool $includeRequireDev = true, array $exclude = []): array
    {
        $composer = $this->readComposerJson();

        if ($composer === null) {
            return [];
        }

        $require = array_map('strtolower', array_map('strval', array_keys($composer['require'] ?? [])));
        $requireDev = $includeRequireDev ? array_map('strtolower', array_map('strval', array_keys($composer['require-dev'] ?? []))) : [];

        $names = array_values(array_unique(array_merge($require, $requireDev)));

        $names = array_values(array_filter(
            $names,
            fn (string $name): bool => $this->shouldCheck($name, $exclude),
        ));

        $versions = $this->readInstalledVersions($names);

        return array_map(
            fn (string $name): array => [
                'name' => $name,
                'version' => $versions[strtolower($name)] ?? null,
            ],
            $names,
        );
    }

    /**
     * Whether a package name should be sent to the health API.
     *
     * @param  array<int, string>  $exclude
     */
    protected function shouldCheck(string $name, array $exclude): bool
    {
        $nameLower = strtolower($name);

        if (Str::startsWith($nameLower, ['ext-', 'lib-'])) {
            return false;
        }

        if (in_array($nameLower, self::PLATFORM_PACKAGES, strict: true)) {
            return false;
        }

        foreach ($exclude as $excluded) {
            if (strtolower($excluded) === $nameLower) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function readComposerJson(): ?array
    {
        $path = $this->composerJsonPath();

        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Resolve installed versions from composer.lock, falling back to InstalledVersions.
     *
     * @param  array<int, string>  $names
     * @return array<string, string>
     */
    protected function readInstalledVersions(array $names): array
    {
        $lock = $this->readLockFile();

        $versions = [];

        foreach (['packages', 'packages-dev'] as $section) {
            foreach ($lock[$section] ?? [] as $package) {
                if (isset($package['name'], $package['version'])) {
                    $versions[strtolower((string) $package['name'])] = (string) $package['version'];
                }
            }
        }

        foreach ($names as $name) {
            $nameLower = strtolower($name);

            if (! isset($versions[$nameLower]) && InstalledVersions::isInstalled($name)) {
                $versions[$nameLower] = InstalledVersions::getVersion($name) ?? 'unknown';
            }
        }

        return $versions;
    }

    /**
     * @return array<string, mixed>
     */
    protected function readLockFile(): array
    {
        $path = $this->basePath.'/composer.lock';

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function composerJsonPath(): string
    {
        return $this->basePath.'/composer.json';
    }
}
