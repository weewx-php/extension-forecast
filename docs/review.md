# Forecast 0.1.0 review — 2026-09-06

## 0.1.1 daylight symbol update — 2026-09-06

Scope: daily symbol selection in `src/Reader.php`, regression tests and Docker
test setup. Reviewed by Codex using the security-review checklist.

The ALL-INKL cache fetched at 17:32:09 UTC contained fog only at 07:00/08:00
local time, clear/mainly clear conditions from 09:00 through 16:00, 12 sunshine
hours and no rain. The provider's daily code was 45. Replaying the same cache
now produces code 0. The rainy day on September 9 still produces a rain symbol
(61), retaining its 13 mm precipitation total.

- Docker PHP 8.1.34: 21 tests / 184 assertions; demo theme: 3 tests / 17 assertions.
- PHPStan maximum level and PHP-CS-Fixer checks pass for the changed PHP files.
- Regression cases cover morning/night fog, prevailing cloud/rain/snow,
  short daytime thunderstorms/freezing rain, clear-code grouping, solar weighting,
  missing/invalid codes, null/zero fallbacks, evening stability, DST, negative and
  fractional timezone offsets, the date line and polar day/night.
- Daily values and hourly codes remain provider data except for the daily symbol.
  Reader operations use existing validated caches without writes or network calls.

### Security review

**Security-Sensitive:** YES (selection from provider input).
**Reviewed By:** Codex. **OWASP Categories Checked:** 10/10.

| Category | Result |
|---|---|
| A01 Access control | PASS: existing tag access and archive boundaries preserved. |
| A02 Cryptography | N/A: no cryptographic or credential changes. |
| A03 Injection | PASS: numeric allowlist; no SQL, shell or markup construction. |
| A04 Design | PASS: bounded hourly loops, solar geometry independent of weather, 75% coverage fallback. |
| A05 Configuration | PASS: no endpoint, settings or runtime configuration changes. |
| A06 Components | PASS: no new dependencies; uses the core solar calculation. |
| A07 Authentication | N/A: no authentication changes. |
| A08 Integrity | PASS: finite validated data, integral WMO-code allowlist, missing data preserved. |
| A09 Logging | N/A: no new logs or sensitive output. |
| A10 SSRF | N/A: no network access added. |

Dependency audit: the package has no Composer dependencies; the core production
lock contains no packages to audit. No critical/high or deferred findings.
**Security Review Status:** PASS.

Scope: entry point, source, settings metadata and explicit CLI migration.
Reviewed by Codex with the security-review checklist, not an independent audit.

- 16 tests / 132 assertions passed under PHP 8.5.1 and PHPUnit 10.5.
- PHPStan maximum level, PHP 8.1 target, passed for package and core.
- Tests cover Tick integration, budgets, retries, stale data, malformed/oversized
  responses, units, null/zero, DST, midnight, archive options, API-key redaction,
  tag validation and idempotent migration with conflict protection.
- Generic installer tests cover approval, hashes, disabled installation, settings,
  secrets, CSRF, configuration revision and recovery.
- No runtime dependencies. Production Composer audit: no packages to audit.

Security-Sensitive: YES. OWASP categories checked: 10/10.

| Category | Result |
|---|---|
| A01 Access control | Existing Admin auth/CSRF. Explicit CLI migration verifies installation and refuses existing options. |
| A02 Cryptography | Verified HTTPS. Keys absent from tags, status and errors; fingerprints are hashes. |
| A03 Injection | No SQL or shell construction. Fixed fields, typed options, numeric validation and escaped Admin output. |
| A04 Design | Bounded request per archive, extension lock, ten-minute retry, atomic publication. |
| A05 Configuration | Installation disabled. Missing coordinates produce status without requests. |
| A06 Components | PHP/core classes only. |
| A07 Authentication | No public endpoint or new identity handling. |
| A08 Integrity | Commit-pinned reviewed files, metadata hashes, bounded parsing, aligned time axes. Migration uses core revision/recovery. |
| A09 Logging | Transport exception text suppressed because it may contain keys. Core audits extension operations. |
| A10 SSRF | Fixed public/customer Open-Meteo endpoints. Core clients reject redirects and bound response size/time. |

Security Review Status: PASS. No deferred critical/high findings.

The package is trusted PHP, not sandboxed. Values are model results, not
observations. The free endpoint is for non-commercial use; customer keys support
commercial access. [API](https://open-meteo.com/en/docs), [access conditions](https://open-meteo.com/en/pricing).
