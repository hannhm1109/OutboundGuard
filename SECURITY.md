# Security and privacy

OutboundGuard is a development/testing aid. Register it only for `dev` and `test`, and keep its configuration in those environments. `enabled: false` removes its services at container compilation.

## Data boundary

| Data | Behavior |
| --- | --- |
| Method, hostname, observed status, duration, outcome | Stored as call metadata |
| URL path | Hidden by default; retained literally only with `capture_paths: true` |
| Query strings, fragments and URL credentials | Excluded from stored targets and fingerprints |
| Headers, cookies, API keys in options, request/response bodies | Never copied into call records or reports |
| Exception messages, debug output, raw options and environment values | Never copied into call records or reports |
| Fingerprint | HMAC of method, normalized origin and path with a fresh collection key |
| Collection key | In-memory collector state; excluded from profiler serialization |

Visible hostnames may identify tenants or internal services. Opting into paths can disclose tokens or personal information embedded in a path; no heuristic can recognize every secret. Leave path capture disabled when uncertain. Fingerprints are endpoint identifiers within one collection, not encryption or anonymous cross-request identifiers.

Core metrics are in memory. If the Symfony profiler is enabled, its usual storage persists the safe report. Only safe scalar groups, warnings, budgets and summary values are serialized by OutboundGuard. Its Twig panel escapes dynamic text.

Other profiler collectors, including Symfony's own HttpClient collector, have independent data policies. OutboundGuard does not sanitize their data or make a production profiler safe.

## Scope and limits

The bundle neither sends telemetry nor stores a separate database of calls. Reports are advisory; they do not block HTTP requests, retry operations, change authentication, or enforce SSRF policy. Keep the application's normal HTTP security configuration.

The collection model handles sequential incoming requests in a shared kernel. Concurrent incoming requests sharing the same mutable container are outside this model. Long-running code outside Symfony's request/reset lifecycle must reset the collector between units of work.

Dependency advisories are checked with `composer audit`; absence of known advisories is not a guarantee that dependencies or application inputs are safe.

## Reporting a vulnerability

Use the repository's **Report a vulnerability** option if private reporting is enabled. Otherwise, open an issue containing only a request for a private contact channel. Do not include credentials, real URLs containing tokens, captured profiles, or exploit details in a public issue.

The package is currently unreleased. Use the latest source revision while evaluating it and review this policy before the first public release.
