# Release preparation

## Recommended first release

- Package: `hanane/outbound-guard-bundle`
- Repository: `https://github.com/hannhm1109/OutboundGuard`
- First tag/version: `v0.1.0` / `0.1.0`
- License: MIT, as declared in `composer.json` and included in `LICENSE`.

Start with `0.1.0`: the MVP is useful and tested, while API and normalization details can still evolve before a `1.0` compatibility commitment. Do not add a `version` field to `composer.json`; Composer/Packagist infer it from the Git tag. Never move a tag once published. See [Composer's version guidance](https://getcomposer.org/doc/04-schema.md#version).

The initial release is `0.1.0`, dated 2026-10-20. Packagist registration is pending. Its public metadata endpoint returned 404 when checked on 2026-10-20; this is not a name reservation or proof of account/vendor ownership. Verify availability and your Packagist permissions at submission time.

## Suggested descriptions

GitHub repository description:

> A Symfony bundle that detects excessive, duplicate and slow outbound HTTP calls, with safe metrics and profiler integration.

Packagist/Composer description:

> Detect excessive, duplicated and slow Symfony HttpClient calls with safe metrics, configurable budgets and profiler integration.

Thirty-second interview explanation:

> OutboundGuard detects the external-HTTP equivalent of N+1 queries in Symfony applications. It decorates the default and scoped HttpClient services, preserving asynchronous calls while collecting minimal metadata. A pure analyzer applies global call and duration budgets and detects repeated endpoints. Developers inspect immutable reports in tests or a native profiler panel. The important decisions are request reset isolation, query-free keyed fingerprints, hidden paths by default, and serializing only safe profiler snapshots. It observes and reports without changing business behavior.

## Local release checks

```console
composer install
composer check
composer audit
composer archive --format=zip --dir=var/release --file=outbound-guard-bundle
```

Verify the archive contains `composer.json`, `LICENSE`, `README.md`, `src/`, `config/services.php` and `templates/`. It must exclude `vendor/`, tests, caches, local tools, credentials, profiler data and `.git`. `archive.exclude` controls Composer archives; `.gitattributes` also excludes development files from Git archives.

Install an extracted archive in an isolated Symfony consumer using a local path repository. Supply a temporary `options.versions` mapping to `0.1.0` in the consumer's repository definition because the extracted, untagged archive has no Git version metadata. Check that only runtime dependencies install with `--no-dev`, the kernel boots without Twig/WebProfiler, and a mock HTTP call produces a safe report. This override is for the smoke test only; it does not belong in the released manifest.

The repository's `tests/Fixtures` kernel is the maintained minimal example application. It exercises real service decoration, scoped clients, request resets, streamed responses, and profiler storage/rendering without calling external APIs.

## Checklist

Prepared in this repository:

- [x] PSR-4 package structure and explicit runtime dependencies.
- [x] Optional profiler dependencies kept out of runtime requirements.
- [x] Composer homepage, author, source, documentation, issues and security links.
- [x] MIT license file and initial changelog.
- [x] Documented configuration, zero/equality behavior, privacy and limitations.
- [x] Regression coverage for buffering defaults and invalid configuration types.
- [x] Development/cache/credential exclusions for release packaging.
- [x] CI matrix for supported Symfony branches and dependency lower-bound checks.

Required when actually publishing:

- [ ] Confirm the final commit passes local checks and all GitHub Actions jobs are green.
- [ ] Recheck dependency advisories and package-name/account permissions.
- [ ] Review the generated archive and repeat the clean-consumer install.
- [x] Move the initial changelog entry from Unreleased to `0.1.0` with the actual release date.
- [ ] Create and push the annotated `v0.1.0` tag on the reviewed commit.
- [ ] Submit the repository URL on Packagist and configure its GitHub update integration.
- [ ] Verify Packagist lists `0.1.0`, then test `composer require --dev hanane/outbound-guard-bundle:^0.1` without a custom repository.
- [ ] Update README publication status and create concise GitHub release notes.

Tagging and Packagist submission are separate publication actions. The GitHub tag makes the release available through a VCS repository; the standard Composer installation becomes available after Packagist indexes the package. Packagist's submission/update process is described in [its official documentation](https://packagist.org/about).
