# Changelog

## Unreleased

Initial release candidate: `0.1.0`.

### Added

- Transparent observation of Symfony's default and scoped HttpClient services.
- In-memory request metrics, reset isolation, and safe endpoint fingerprints.
- Global budgets, duplicate/slow-call detection, and deterministic warnings.
- Immutable reports for application tests and an optional native profiler panel.
- PHP 8.1+/Symfony 6.4, PHP 8.2+/Symfony 7.4, and PHP 8.4.1+/Symfony 8.1 compatibility.
- MIT license, installation guidance, privacy notes and release checklist.

### Fixed

- Preserve buffering defaults set through `withOptions()` on the monitoring client, including chained clones and per-request overrides.

No release tag or Packagist publication has been created yet.
