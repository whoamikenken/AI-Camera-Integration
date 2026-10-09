# Dispatch Directive — Worker Milestone 3 (SEC-17, SEC-18)

## Mission
Harden Content-Security-Policy (CSP) headers and resolve all reported npm and Composer upstream dependency vulnerabilities without breaking frontend builds, WebAssembly video player decoding, or automated tests.

## Authoritative Inputs
- User Request: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md`
- Tasks Spec: `/home/wsk-devops2/AI-Camera-Integration/tasks-security.md` (SEC-17, SEC-18)
- Technical Blueprint: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_survey_3/handoff.md`

## Exclusively Owned Files
- `app/Http/Middleware/SecurityHeaders.php`
- `package.json`
- `package-lock.json`
- `composer.json`
- `composer.lock`
- `tests/Feature/SecurityRemediationTest.php`

## Implementation Tasks

### 1. SEC-17: Content-Security-Policy Hardening (`app/Http/Middleware/SecurityHeaders.php`)
- Construct CSP dynamically:
  - **`script-src`**: `'self' https://static.cloudflareinsights.com`. In production, eliminate `'unsafe-eval'` and use `'wasm-unsafe-eval'` (required for WebAssembly H.264/H.265 software video decoding in `public/player/decoder.wasm`). In non-production (`!app()->environment('production')`), include `'unsafe-eval'` if required by Vite dev tools. Retain `'unsafe-inline'` as needed for Vite inline styles/scripts.
  - **`worker-src`**: `'self' blob:;` (required for `/player/decoder_worker.js`).
  - **`connect-src`**: Remove wildcards `https:`, `ws:`, `wss:`.
    Scope strictly to `'self'`, `https://cloudflareinsights.com`, and configured Reverb WebSocket origins (`ws://{$host}:{$port}`, `wss://{$host}:{$port}`). In non-production (`!app()->environment('production')`), include Vite dev server origins (`ws://localhost:*`, `wss://localhost:*`, `ws://127.0.0.1:*`, `wss://camera-dev.8gategames.com`).
  - **`img-src`**: Remove wildcard `https:`.
    Scope strictly to `'self'`, `data:`, `blob:`, and configured cloud storage endpoints (`config('filesystems.disks.s3.url')`, S3 endpoints if set).
  - **`style-src`**: `'self' 'unsafe-inline' https://fonts.googleapis.com;`
  - **`font-src`**: `'self' data: https://fonts.gstatic.com;`
  - **`frame-ancestors`**: `'none';`

### 2. SEC-18: Dependency Vulnerability Patching (`package.json`, `composer.json`)
- **NPM**:
  - In `package.json`:
    - Update `"vue": "^3.5.43"`.
    - Add `"overrides": { "shell-quote": "^1.11.0", "source-map-js": "^1.2.2" }`.
  - Run `npm update` and execute `npm audit`. Ensure 0 vulnerabilities (exit code 0).
  - Verify frontend build: `npm run build` succeeds cleanly.
- **Composer**:
  - In `composer.json`:
    - Update `"laravel/framework": "^13.30"`.
    - Add `"league/commonmark": "^2.10.3"`.
    - Add `"league/flysystem": "^3.36.0"`.
  - Run `composer update laravel/framework league/commonmark league/flysystem`.
  - Run `composer audit`. Ensure 0 security advisories (exit code 0).

### 3. Tests & Regression Verification
- Add dedicated test methods in `tests/Feature/SecurityRemediationTest.php`:
  - `test_sec17_content_security_policy_directives_are_hardened` (asserts no wildcard `https:`, `ws:`, `wss:` in `connect-src` or `img-src`; asserts `'wasm-unsafe-eval'`; asserts Reverb endpoints).
  - `test_sec18_dependency_audit_clean` (or programmatic verification that audit runs clean and patched versions are present).
- Run test suites:
  - `php artisan test --filter=SecurityRemediationTest`
  - `php artisan test`
  - `npm run build`
  - Ensure zero regressions.

## MANDATORY INTEGRITY WARNING
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A teamwork_preview_auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

## Working Directory & Handoff
Working directory: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m3_1`
Write handoff report to: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m3_1/handoff.md`
Report back via `send_message` when done.


## 2026-10-07T07:30:09Z
You are assigned as Worker for Milestone 3: Environment, Content-Security-Policy & Upstream Dependencies (SEC-17, SEC-18) in Intelligent AI Camera Hub.
Working directory: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m3_1
Exclusively Owned Files:
- app/Http/Middleware/SecurityHeaders.php
- package.json
- package-lock.json
- composer.json
- composer.lock
- tests/Feature/SecurityRemediationTest.php
