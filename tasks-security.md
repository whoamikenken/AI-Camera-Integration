# Security Remediation Plan & Task Tracking
**Project:** Intelligent AI Camera Hub (`AI-Camera-Integration`)  
**Assessment Date:** 2026-10-07  
**Overall Risk Status:** RESOLVED / LOW (All SEC-11 through SEC-19 Remediated)

---

## Task Overview & Progress Tracker

### Current Active Findings (Audit Cycle: 2026-10-07)
- [x] **SEC-11 (Critical):** Remediate BOLA/IDOR on Attendance Summary Endpoint (`EmployeeController::attendanceSummary`)
- [x] **SEC-12 (Critical):** Isolate Real-Time WebSocket Notification Broadcasting to Per-User Channels (`NotificationCreated`)
- [x] **SEC-13 (High):** Disable Unauthenticated WAN MQTT Tunnel & Prevent Rogue Device Ingestion (`MqttListenCommand`, `start-dev.sh`)
- [x] **SEC-14 (High):** Deprecate URL Query-String Token Auth in Favor of Signed Media Routes (`AuthenticateQueryToken`, `formatMediaUrl`)
- [x] **SEC-15 (Medium):** Restrict Biometric File Upload MIME Types to Disallow SVG / Prevent Stored XSS (`PersonnelController`, `ImageStorageService`)
- [x] **SEC-16 (Medium):** Enforce Consistent SSRF Protection on `photo_path` in `PersonnelController`
- [x] **SEC-17 (Medium):** Harden Content-Security-Policy Directives (`SecurityHeaders`)
- [x] **SEC-18 (Medium):** Update Vulnerable NPM and Composer Upstream Dependencies (`@vue/server-renderer`, `concurrently`, `laravel/framework`)
- [x] **SEC-19 (Low):** Prevent Reverse-Proxy Loopback IP Authentication Bypass in Webhooks (`HttpWebhookController`)

### Previously Resolved & Archived Tasks
- [x] **SEC-01 (High):** Remove Hardcoded Backdoor Secret & Enforce Mandatory Authentication in Camera Webhooks `[ARCHIVED · Jules 10878193843185705609]`
- [x] **SEC-02 (High):** Add Permission Middleware to Telemetry Endpoints (`access-logs`, `stranger-snaps`, `sync-tasks`) `[ARCHIVED · Jules 9638457024983864081]`
- [x] **SEC-03 (High):** Enforce Tenant/User Scoping on Leave and Regularization Listing (BOLA/IDOR) `[ARCHIVED · Jules 14557360042084046356]`
- [x] **SEC-04 (High):** Migrate Biometric Image Ingestion from Public Disk to Private Biometrics Storage `[ARCHIVED · Jules 9808187318662239942]`
- [x] **SEC-05 (Medium):** Remove Mock Entity Auto-Creation from `VisitorController::block` `[ARCHIVED · Jules 17649228985988439228]`
- [x] **SEC-06 (Medium):** Revoke Active Bearer Tokens on User Password Change `[ARCHIVED · Jules 17649228985988439228]`
- [x] **SEC-07 (Medium):** Constrain Media Route Path Traversal and Directory Scope in `/api/media/{path}` `[ARCHIVED · Jules 9808187318662239942]`
- [x] **SEC-08 (Medium):** Remove Unauthenticated Mock Endpoint `/action/{operator}` from Production Web Routes `[ARCHIVED · Jules 17649228985988439228]`
- [x] **SEC-09 (Medium):** Patch High & Moderate Vulnerabilities in NPM and Composer Dependencies (`axios`, `league/commonmark`) `[ARCHIVED · Jules 9441031566168839526]`
- [x] **SEC-10 (Low):** Apply Rate Limiting to Unrestricted Public Endpoints (`/api/settings/public`) `[ARCHIVED · Jules 9638457024983864081]`

---

## Detailed Remediation Tasks (Active Cycle: 2026-10-07)

### SEC-11: Remediate BOLA/IDOR on Attendance Summary Endpoint (Critical)
- **Target Files:**
  - `routes/api.php`
  - `app/Http/Controllers/EmployeeController.php`
- **OWASP Category:** A01:2021-Broken Access Control
- **Objective:** Prevent non-manager employees from querying attendance summaries and biometric punch logs of other employees.
- **Root Cause:**
  `routes/api.php` lists `selfservice.view` alongside `employees.view` in the `permission` middleware (which uses OR logic). In `EmployeeController::attendanceSummary`, there is no validation verifying that an employee with only `selfservice.view` is restricted to their own ID (`$request->user()->employee?->id === $id`).
- **Action Items:**
  1. In `EmployeeController::attendanceSummary`, inspect `$user->hasPermission(['employees.view', 'attendance.view', 'employees.manage'])` or managerial roles.
  2. If the user does not possess managerial privileges, enforce `$user->employee?->id === (int) $id`. If mismatched or user has no employee profile, return `403 Forbidden`.
  3. Add adversarial unit/feature test verifying that employee A receives HTTP 403 when requesting employee B's attendance summary.

---

### SEC-12: Isolate Real-Time WebSocket Notification Broadcasting to Per-User Channels (Critical)
- **Target Files:**
  - `app/Events/NotificationCreated.php`
  - `routes/channels.php`
  - `resources/js/App.vue`
- **OWASP Category:** A01:2021-Broken Access Control
- **Objective:** Prevent cross-user data leakage of confidential in-app notifications over Laravel Reverb WebSockets.
- **Root Cause:**
  `NotificationCreated` broadcasts to `new PrivateChannel('notifications')`. `routes/channels.php` authorizes any authenticated user (`$user !== null`) to subscribe to `private-notifications`. Consequently, all private notifications (leave requests, reprimands, security notices) are broadcast to all connected users.
- **Action Items:**
  1. Update `NotificationCreated::broadcastOn()` to broadcast strictly to `new PrivateChannel('notifications.' . $this->notification->user_id)`.
  2. Deprecate or remove the permissive global `Broadcast::channel('notifications', ...)` in `routes/channels.php`.
  3. Update `resources/js/App.vue` to join the user-scoped channel: `echo.private(`notifications.${authStore.user.id}`)`.

---

### SEC-13: Disable Insecure WAN MQTT Tunnel & Reject Rogue Device Ingestion (High)
- **Target Files:**
  - `start-dev.sh`
  - `.env.example`
  - `.env`
  - `app/Console/Commands/MqttListenCommand.php`
- **OWASP Category:** A05:2021-Security Misconfiguration / A07:2021-Identification and Authentication Failures
- **Objective:** Protect edge MQTT broker from anonymous WAN access and stop automatic creation of active rogue devices.
- **Root Cause:**
  `start-dev.sh` launches a public `bore` TCP tunnel (`bore.pub:35803`) exposing the unauthenticated local broker (`MQTT_AUTH=false`). `MqttListenCommand` calls `Device::firstOrCreate(..., ['is_active' => true])` upon receiving messages, permitting any attacker on the internet to inject fake punches and active devices.
- **Action Items:**
  1. Set `ENABLE_INSECURE_MQTT_TUNNEL=false` by default in `start-dev.sh` and configuration files.
  2. In `MqttListenCommand`, verify if device exists in `devices` table. If un-enrolled, drop telemetry or create in inactive status (`is_active = false`), matching `HttpWebhookController`.
  3. Enforce `MQTT_AUTH=true` with mandatory authentication credentials in all environments.

---

### SEC-14: Deprecate Query-String Token Auth in Favor of Signed Media Routes (High)
- **Target Files:**
  - `app/Http/Middleware/AuthenticateQueryToken.php`
  - `bootstrap/app.php`
  - `routes/api.php`
  - `resources/js/utils/media.js`
- **OWASP Category:** A07:2021-Identification and Authentication Failures / CWE-598
- **Objective:** Eliminate Sanctum API Bearer token exposure in URL query strings, server access logs, and HTTP Referer headers.
- **Root Cause:**
  `AuthenticateQueryToken` is prepended to the global `api` middleware group. `formatMediaUrl` appends `?token=` to all biometric media URLs in `<img>` tags, leaking long-lived bearer tokens into browser history and log files.
- **Action Items:**
  1. Remove `AuthenticateQueryToken` from the global `api` middleware stack in `bootstrap/app.php`.
  2. Implement temporary signed route helper (`URL::temporarySignedRoute`) for streaming private biometric media via `/api/media/{path}`.
  3. Update `resources/js/utils/media.js` and frontend components to use signed media URLs or standard authenticated axios blob streams rather than URL query tokens.

---

### SEC-15: Restrict Biometric File Upload MIME Types to Disallow SVG / Stored XSS (Medium)
- **Target Files:**
  - `app/Http/Controllers/PersonnelController.php`
  - `app/Services/ImageStorageService.php`
  - `routes/api.php`
- **OWASP Category:** A03:2021-Injection (Stored XSS)
- **Objective:** Prevent upload of executable SVG XML files as personnel photos and block script execution in `/api/media/{path}`.
- **Root Cause:**
  The `image` validation rule in Laravel permits `image/svg+xml`. When served via `/api/media/{path}`, SVG documents can execute inline scripts in the browser. Edge AI face recognition cameras only support raster images.
- **Action Items:**
  1. Replace `'photo' => 'nullable|image|max:10240'` with `'photo' => 'nullable|file|mimes:jpeg,jpg,png,webp|max:10240'` in `PersonnelController.php`.
  2. In `ImageStorageService::getMedia()`, ensure SVG files are disallowed or forced to `Content-Disposition: attachment` with sandboxing headers.

---

### SEC-16: Enforce Consistent SSRF Protection on `photo_path` in Personnel Controller (Medium)
- **Target Files:**
  - `app/Http/Controllers/PersonnelController.php`
- **OWASP Category:** A10:2021-Server-Side Request Forgery (SSRF)
- **Objective:** Prevent bypass of anti-SSRF address filters when external URLs are provided in `photo_path`.
- **Root Cause:**
  `PersonnelController::store` and `update` check `isSafeUrl` only if `!empty($validated['photo_url'])`. If an attacker supplies a URL in `photo_path` and leaves `photo_url` empty, the validation exception is skipped and the parsed path is stored into the database.
- **Action Items:**
  1. Apply `filter_var($source, FILTER_VALIDATE_URL)` and `isSafeUrl($source)` validation consistently to both `photo_url` and `photo_path`.
  2. Throw `ValidationException` immediately if an unsafe address (private IP, loopback, cloud metadata `169.254.169.254`) is provided in either field.

---

### SEC-17: Harden Content-Security-Policy Directives (Medium)
- **Target Files:**
  - `app/Http/Middleware/SecurityHeaders.php`
- **OWASP Category:** A05:2021-Security Misconfiguration
- **Objective:** Restrict unsafe script evaluation and wildcard network connections in CSP headers.
- **Root Cause:**
  `SecurityHeaders` includes `'unsafe-inline'`, `'unsafe-eval'`, and wildcard `https:` in `connect-src` and `img-src`, allowing attackers to exfiltrate captured tokens to arbitrary remote servers.
- **Action Items:**
  1. Restrict `connect-src` to `'self'`, configured Reverb WebSocket origins, and Cloudflare insights.
  2. Restrict `img-src` to `'self'`, `data:`, `blob:`, and configured cloud storage endpoints.
  3. Work towards removing `'unsafe-eval'` from production script policy.

---

### SEC-18: Update Vulnerable NPM and Composer Upstream Dependencies (Medium)
- **Target Files:**
  - `package.json`
  - `package-lock.json`
  - `composer.json`
  - `composer.lock`
- **OWASP Category:** A06:2021-Vulnerable and Outdated Components
- **Objective:** Resolve reported CVEs and security advisories in application dependencies.
- **Action Items:**
  1. Run `npm update @vue/server-renderer` (GHSA-g2v6-rqmx-r4w6) and update `source-map-js` (GHSA-68fv-2mgg-jv7q).
  2. Resolve transitive `shell-quote` advisory in `concurrently` (GHSA-pqg4-j6r4-53mv).
  3. Update `laravel/framework` to `>=12.69.0` (CVE-2026-102279), `league/commonmark` (GHSA-3q6v-r5mr-hxv8), and `league/flysystem` (CVE-2026-102601).

---

### SEC-19: Prevent Reverse-Proxy Loopback IP Authentication Bypass in Webhooks (Low)
- **Target Files:**
  - `app/Http/Controllers/HttpWebhookController.php`
  - `bootstrap/app.php`
- **OWASP Category:** A07:2021-Identification and Authentication Failures
- **Objective:** Prevent spoofed webhook authentication when the application runs behind reverse proxies.
- **Root Cause:**
  `HttpWebhookController::authenticateWebhook` automatically accepts requests from `127.0.0.1` and `::1`. If trusted proxies are not configured in `bootstrap/app.php`, `$request->ip()` can evaluate to `127.0.0.1` for external client requests routed through a reverse proxy.
- **Action Items:**
  1. Restrict the loopback bypass to non-production environments (`app()->environment('local', 'testing')`).
  2. Configure `$middleware->trustProxies(...)` in `bootstrap/app.php` to ensure real client IPs are resolved.

---

## Previously Archived Tasks (Reference)

### SEC-01: Remove Hardcoded Secret & Enforce Webhook Authentication (High) `[ARCHIVED]`
- **Target Files:** `app/Http/Controllers/HttpWebhookController.php`
- **OWASP Category:** A07:2021-Identification and Authentication Failures
- **Status:** Resolved in Jules session 10878193843185705609.

### SEC-02: Protect Vision Telemetry and Sync REST Endpoints (High) `[ARCHIVED]`
- **Target Files:** `routes/api.php`, `app/Http/Controllers/AccessLogController.php`, `app/Http/Controllers/StrangerSnapController.php`
- **OWASP Category:** A01:2021-Broken Access Control
- **Status:** Resolved in Jules session 9638457024983864081.

### SEC-03: Remediate BOLA/IDOR on Self-Service Requests (High) `[ARCHIVED]`
- **Target Files:** `app/Http/Controllers/LeaveController.php`, `app/Http/Controllers/RegularizationController.php`
- **OWASP Category:** A01:2021-Broken Access Control
- **Status:** Resolved in Jules session 14557360042084046356.

### SEC-04: Enforce Private Disk Storage for Facial Biometrics (High) `[ARCHIVED]`
- **Target Files:** `app/Services/ImageStorageService.php`, `config/filesystems.php`
- **OWASP Category:** A04:2021-Insecure Design
- **Status:** Resolved in Jules session 9808187318662239942.

### SEC-05: Eliminate Mock ID Creation in `VisitorController::block` (Medium) `[ARCHIVED]`
- **Target Files:** `app/Http/Controllers/VisitorController.php`
- **OWASP Category:** A01:2021-Broken Access Control
- **Status:** Resolved in Jules session 17649228985988439228.

### SEC-06: Revoke Bearer Tokens on User Password Change (Medium) `[ARCHIVED]`
- **Target Files:** `app/Http/Controllers/AuthController.php`
- **OWASP Category:** A07:2021-Identification and Authentication Failures
- **Status:** Resolved in Jules session 17649228985988439228.

### SEC-07: Constrain Media Streaming Route Path Traversal (Medium) `[ARCHIVED]`
- **Target Files:** `routes/api.php`, `app/Services/ImageStorageService.php`
- **OWASP Category:** A01:2021-Broken Access Control
- **Status:** Resolved in Jules session 9808187318662239942.

### SEC-08: Decommission Development Mock Route in Production (Medium) `[ARCHIVED]`
- **Target Files:** `routes/web.php`, `bootstrap/app.php`
- **OWASP Category:** A05:2021-Security Misconfiguration
- **Status:** Resolved in Jules session 17649228985988439228.

### SEC-09: Remediate High-Severity Upstream Dependencies (Medium) `[ARCHIVED]`
- **Target Files:** `package.json`, `composer.json`
- **OWASP Category:** A06:2021-Vulnerable and Outdated Components
- **Status:** Resolved in Jules session 9441031566168839526.

### SEC-10: Rate Limit Public Settings Endpoint (Low) `[ARCHIVED]`
- **Target Files:** `routes/api.php`
- **OWASP Category:** A04:2021-Insecure Design
- **Status:** Resolved in Jules session 9638457024983864081.
