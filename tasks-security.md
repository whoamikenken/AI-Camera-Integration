# Security Remediation Plan & Task Tracking
**Project:** Intelligent AI Camera Hub (`AI-Camera-Integration`)  
**Assessment Date:** 2026-10-04  
**Overall Risk Status:** HIGH (Active Remediations Required)

---

## Task Overview & Progress Tracker

- [ ] **SEC-01 (High):** Remove Hardcoded Backdoor Secret & Enforce Mandatory Authentication in Camera Webhooks
- [ ] **SEC-02 (High):** Add Permission Middleware to Telemetry Endpoints (`access-logs`, `stranger-snaps`, `sync-tasks`)
- [ ] **SEC-03 (High):** Enforce Tenant/User Scoping on Leave and Regularization Listing (BOLA/IDOR)
- [ ] **SEC-04 (High):** Migrate Biometric Image Ingestion from Public Disk to Private Biometrics Storage
- [ ] **SEC-05 (Medium):** Remove Mock Entity Auto-Creation from `VisitorController::block`
- [ ] **SEC-06 (Medium):** Revoke Active Bearer Tokens on User Password Change
- [ ] **SEC-07 (Medium):** Constrain Media Route Path Traversal and Directory Scope in `/api/media/{path}`
- [ ] **SEC-08 (Medium):** Remove Unauthenticated Mock Endpoint `/action/{operator}` from Production Web Routes
- [ ] **SEC-09 (Medium):** Patch High & Moderate Vulnerabilities in NPM and Composer Dependencies (`axios`, `league/commonmark`)
- [ ] **SEC-10 (Low):** Apply Rate Limiting to Unrestricted Public Endpoints (`/api/settings/public`)

---

## Detailed Remediation Tasks

### SEC-01: Remove Hardcoded Secret & Enforce Webhook Authentication (High)
- **Target Files:**
  - `app/Http/Controllers/HttpWebhookController.php`
- **OWASP Category:** A07:2021-Identification and Authentication Failures
- **Objective:** Eliminate backdoor credentials and prevent unauthenticated creation of rogue camera devices.
- **Action Items:**
  1. Remove `|| $headerSecret === 'valid-camera-secret'` from `HttpWebhookController::authenticateWebhook()`.
  2. In `handleHeartbeat()`, ensure requests are rejected if `CAMERA_WEBHOOK_SECRET` is unset and credentials fail or device is not pre-registered.
  3. Prohibit automatic `Device::create` from unverified webhook heartbeats.

---

### SEC-02: Protect Vision Telemetry and Sync REST Endpoints (High)
- **Target Files:**
  - `routes/api.php`
  - `app/Http/Controllers/AccessLogController.php`
  - `app/Http/Controllers/StrangerSnapController.php`
  - `app/Http/Controllers/SyncTaskController.php`
- **OWASP Category:** A01:2021-Broken Access Control
- **Objective:** Align REST API access control with Reverb WebSocket channel policies.
- **Action Items:**
  1. Attach `permission:attendance.view,devices.view` to `GET /api/access-logs` and `GET /api/access-logs/{accessLog}`.
  2. Attach `permission:devices.view` (or `security` role) to `GET /api/stranger-snaps` and `GET /api/stranger-snaps/{strangerSnap}`.
  3. Attach `permission:devices.manage` to `GET /api/sync-tasks`.
  4. Attach `permission:devices.view,attendance.view` to `GET /api/stats`.

---

### SEC-03: Remediate BOLA/IDOR on Self-Service Requests (High)
- **Target Files:**
  - `app/Http/Controllers/LeaveController.php`
  - `app/Http/Controllers/RegularizationController.php`
- **OWASP Category:** A01:2021-Broken Access Control
- **Objective:** Prevent non-manager employees from viewing company-wide leave requests and regularization entries.
- **Action Items:**
  1. In `LeaveController::listRequests()`, check if the user has `leaves.manage` or management roles (`admin`, `hr-manager`, `super-admin`). If not, force `$query->where('employee_id', $user->employee?->id)`.
  2. In `LeaveController::listBalances()`, constrain queries for non-managers to their own employee ID.
  3. In `RegularizationController::index()`, check if the user has `attendance.manage`. If not, force `$query->where('employee_id', $user->employee?->id)`.

---

### SEC-04: Enforce Private Disk Storage for Facial Biometrics (High)
- **Target Files:**
  - `app/Services/ImageStorageService.php`
  - `config/filesystems.php`
- **OWASP Category:** A04:2021-Insecure Design
- **Objective:** Prevent unauthenticated access to facial snapshots and enrolled employee pictures via the public web server.
- **Action Items:**
  1. Change default disk in `ImageStorageService` from `'public'` to the configured biometric disk (`$this->getDisk()`, defaulting to `'biometrics'`).
  2. Update `storeBase64Image()`, `storeFromBase64()`, `storeUploadedImage()`, and `storeFromUrlOrPath()` to write to `$this->getDisk()`.
  3. Ensure all frontend viewing is routed through the authenticated `GET /api/media/{path}` streaming endpoint.

---

### SEC-05: Eliminate Mock ID Creation in `VisitorController::block` (Medium)
- **Target Files:**
  - `app/Http/Controllers/VisitorController.php`
- **OWASP Category:** A01:2021-Broken Access Control
- **Objective:** Prevent attackers from polluting the database with arbitrary visitor IDs.
- **Action Items:**
  1. Replace `Visitor::create(['id' => $id, ...])` in `VisitorController::block()` with `Visitor::findOrFail($id)`.

---

### SEC-06: Revoke Bearer Tokens on User Password Change (Medium)
- **Target Files:**
  - `app/Http/Controllers/AuthController.php`
- **OWASP Category:** A07:2021-Identification and Authentication Failures
- **Objective:** Ensure compromised tokens cannot maintain persistence after credential rotation.
- **Action Items:**
  1. In `AuthController::changePassword()`, revoke all tokens or all tokens except current: `$user->tokens()->delete()`.
  2. In `AuthController::updateProfile()`, when password is updated, revoke other active personal access tokens.

---

### SEC-07: Constrain Media Streaming Route Path Traversal (Medium)
- **Target Files:**
  - `routes/api.php`
  - `app/Services/ImageStorageService.php`
- **OWASP Category:** A01:2021-Broken Access Control
- **Objective:** Prevent path traversal or probing of unauthorized files inside application storage disks.
- **Action Items:**
  1. Validate in `ImageStorageService::getMedia()` that the path contains no directory traversal sequences (`..`).
  2. Enforce that `$cleanPath` matches allowed subdirectory prefixes (`personnel/`, `snaps/`, `scenes/`, `verification_snaps/`, `verification_scenes/`, `visitors/`).

---

### SEC-08: Decommission Development Mock Route in Production (Medium)
- **Target Files:**
  - `routes/web.php`
  - `bootstrap/app.php`
- **OWASP Category:** A05:2021-Security Misconfiguration
- **Objective:** Prevent exposure of test endpoints in production environments.
- **Action Items:**
  1. Wrap `Route::post('/action/{operator}', ...)` in `if (app()->environment('local', 'testing'))`.
  2. Restrict CSRF exception for `action/*` in `bootstrap/app.php` to non-production environments.

---

### SEC-09: Remediate High-Severity Upstream Dependencies (Medium)
- **Target Files:**
  - `package.json`
  - `composer.json`
- **OWASP Category:** A06:2021-Vulnerable and Outdated Components
- **Objective:** Mitigate known CVEs in NPM and Composer packages.
- **Action Items:**
  1. Run `npm install axios@^1.21.0` or `npm audit fix` to eliminate prototype pollution and ReDoS vulnerabilities in Axios.
  2. Run `composer update league/commonmark` to patch quadratic-time DoS and HTML bypass advisories.
  3. Run `composer update laravel/framework league/flysystem` to patch framework advisories.

---

### SEC-10: Rate Limit Public Settings Endpoint (Low)
- **Target Files:**
  - `routes/api.php`
- **OWASP Category:** A04:2021-Insecure Design
- **Objective:** Protect public configuration endpoint from scraping and resource exhaustion.
- **Action Items:**
  1. Apply `throttle:60,1` to `Route::get('settings/public', ...)`.
