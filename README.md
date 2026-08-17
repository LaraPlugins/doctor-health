# LaraPlugins Doctor Health

[![LaraPlugins.io: laraplugins/doctor-health info card](https://laraplugins.io/api/infocard/laraplugins/doctor-health?design=badge&theme=dark)](https://laraplugins.io/plugins/laraplugins/doctor-health)

A [Laravel Doctor](https://github.com/laravel/doctor) diagnostic that checks your
application's composer dependencies against the [LaraPlugins](https://laraplugins.io)
health index and reports the verdict for every indexed package.

[![LaraPlugins.io: laraplugins/doctor-health info card](https://laraplugins.io/api/infocard/laraplugins/doctor-health?design=facts&theme=dark)](https://laraplugins.io/plugins/laraplugins/doctor-health)

When you run `php artisan doctor`, a single `laraplugins` diagnostic is added:

- **fail** — when any dependency is unhealthy (or archived, if `archived_verdict = fail`)
- **warn** — when a dependency is `medium`, or archived (default)
- **notice** — when there are no issues but some packages are not indexed by LaraPlugins
- **pass** — when every indexed dependency is healthy
- **skip** — when there are no dependencies, or the diagnostic is disabled
- **warn** — when the LaraPlugins API cannot be reached (configurable)

Each verdict includes a per-package breakdown in its details:

```
spatie/laravel-permission 6.9.0 → Healthy (latest 6.9.0)
laravel/framework 13.23.0 → Medium
vendor/archived 1.0.0 → Healthy (archived)
```

## Installation

```bash
composer require laraplugins/doctor-health
```

The package registers automatically via Composer package discovery. The
`laraplugins/doctor-health` diagnostic is added to your existing Doctor suite.

To publish and customize the configuration:

```bash
php artisan vendor:publish --tag=doctor-health-config
```

## What data is sent

When the diagnostic runs, the following is sent to `https://laraplugins.io/api/v1/packages/health`:

- The **names and installed versions** of every package in your `composer.json`
  `require` and `require-dev` sections.
- No source code, configuration values, environment variables, or other
  project data.

LaraPlugins logs the received package names for ecosystem analytics and to
discover packages that are not yet indexed. It does not store IP addresses —
they are salted-hashed for abuse protection only (rate limiting).

If your project is air-gapped or you prefer not to send dependency data,
disable the diagnostic entirely:

```php
// config/laraplugins-doctor-health.php
'enabled' => false,
```

or exclude individual packages:

```php
'exclude_packages' => ['laraplugins/doctor-health', 'vendor/private'],
```
Also, we have a daily exported JSONL format available. If there is any interest from sponsors, we may enable the use of that data in the future so that no data gets out. Please hit me up if your installation needs that. For now, it is not on my roadmap.. 

## Configuration

| Key | Default | Description |
|---|---|---|
| `url` | `https://laraplugins.io` | Base URL of the health-check API (`LARAPLUGINS_DOCTOR_URL`) |
| `timeout` | `5` | Total HTTP timeout in seconds |
| `connect_timeout` | `5` | Connection timeout in seconds |
| `retry.times` / `retry.sleep` | `2` / `100` | HTTP retry attempts and delay in ms |
| `enabled` | `true` | Kill-switch for the diagnostic |
| `include_require_dev` | `true` | Send `require-dev` packages too |
| `package_limit` | `250` | Maximum packages sent per run |
| `unreachable_verdict` | `warn` | Verdict when the API is unreachable (`warn`, `skip`, or `error`) |
| `archived_verdict` | `warn` | Verdict for archived/abandoned packages (`warn` or `fail`) |
| `exclude_packages` | `[]` | Packages never sent to the API |
| `http_headers` | `[]` | Extra headers on every request |

## Selecting the diagnostic

Use Doctor's normal selector options:

```bash
php artisan doctor --only=laraplugins
php artisan doctor --except=laraplugins
php artisan doctor --only=laraplugins/doctor-health
```

## Development

The package is developed with [Testbench Workbench](https://github.com/orchestral/testbench):

```bash
composer test          # run the Pest test suite
composer build         # rebuild the workbench app
./vendor/bin/pint      # code style
./vendor/bin/phpstan   # static analysis
```

## License

MIT

[![LaraPlugins.io: laraplugins/doctor-health info card](https://laraplugins.io/api/infocard/laraplugins/doctor-health?design=simple&theme=dark)](https://laraplugins.io/plugins/laraplugins/doctor-health)
