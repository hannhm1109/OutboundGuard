# Contributing

Clone this repository, install dependencies, and run the checks:

```console
composer install
composer check
composer audit
```

Use a PHP version supported by the Symfony branch you are testing. Composer picks compatible dependencies; the CI matrix explicitly tests Symfony 6.4, 7.4 and 8.1. This library does not commit its lock file.

`composer check` runs PHPUnit, PHPStan level 8, strict Composer validation and strict PSR-4 autoload checks. Tests use Symfony mock responses and a minimal kernel; they never call an external API. Composer installation and dependency auditing require network access.

The development-only HTML parser requires version 2.7.5 or newer to avoid PHP 8.1 deprecations in browser assertions. It is not a runtime dependency of the bundle.

Keep changes focused on observing outbound HTTP behavior. Do not add real credentials, `.env` files, native profiler storage, request/response bodies, or unsanitized URLs to fixtures or reports. Use placeholders when demonstrating sensitive inputs.

Add behavior tests for bug fixes. Changes to HttpClient observation should preserve lazy responses, streaming, cancellation, exceptions, progress callbacks, and reset isolation. Changes to the profiler should be exercised through the real kernel and saved profiles, including HTML escaping and serialization.

The existing [test fixtures](tests/Fixtures) are the example Symfony application: FrameworkBundle, an optional profiler, mock HTTP responses, and small checkout/streaming routes. A second standalone application is unnecessary for the current scope.

Read [the architecture notes](docs/architecture.md) before changing integration or data boundaries. Follow [SECURITY.md](SECURITY.md) for security reports and [the release checklist](docs/releasing.md) for publication.
