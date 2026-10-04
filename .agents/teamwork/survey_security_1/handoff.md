# Comprehensive Security Codebase Survey & Remediation Blueprint

**Project:** Intelligent AI Camera Hub (`AI-Camera-Integration`)  
**Survey Agent:** `survey_security_1` (Security Codebase Surveyor)  
**Date:** 2026-10-01  
**Scope:** Remediation of `tasks-security.md` (SEC-01 through SEC-15)  
**Overall Risk Status:** CRITICAL  

---

## 1. Observation

A complete, read-only architectural survey of the AI-Camera-Integration codebase was conducted. Every target file in tasks SEC-01 through SEC-15 was directly inspected, verified via `view_file`, `grep_search`, `npm audit`, and `composer audit`. The exact observations are detailed below:

### SEC-01: Camera Webhook Push Endpoints
- **Files Inspected:**
  - `routes/api.php` lines 32–34:
    ```php
    Route::post('/Subscribe/heartbeat', [HttpWebhookController::class, 'handleHeartbeat']);
    Route::post('/Subscribe/Verify', [HttpWebhookController::class, 'handleVerify']);
    Route::post('/Subscribe/Snap', [HttpWebhookController::class, 'handleSnap']);
    ```
  - `routes/web.php` lines 35–37:
    ```php
    Route::post('/Subscribe/heartbeat', [\App\Http\Controllers\HttpWebhookController::class, 'handleHeartbeat']);
    Route::post('/Subscribe/Verify', [\App\Http\Controllers\HttpWebhookController::class, 'handleVerify']);
    Route::post('/Subscribe/Snap', [\App\Http\Controllers\HttpWebhookController::class, 'handleSnap']);
    ```
  - `app/Http/Controllers/HttpWebhookController.php`:
    - Lines 39–46 (`handleHeartbeat`):
      ```php
      $device = Device::firstOrCreate(
          ['device_id' => $deviceId],
          [
              'name' => $info['facesname'] ?? $info['Name'] ?? "Camera {$deviceId}",
              'ip_address' => $request->ip() ?: '192.168.1.100',
              'is_active' => true,
          ]
      );
      ```
    - Lines 100–107 (`handleVerify`):
      ```php
      $device = Device::firstOrCreate(
          ['device_id' => $deviceId],
          [
              'name' => "Camera {$deviceId}",
              'ip_address' => $request->ip() ?: '127.0.0.1',
              'is_active' => true,
          ]
      );
      ```
    - Lines 185–192 (`handleSnap`):
      ```php
      $device = Device::firstOrCreate(
          ['device_id' => $deviceId],
          [
              'name' => "Camera {$deviceId}",
              'ip_address' => $request->ip() ?: '127.0.0.1',
              'is_active' => true,
          ]
      );
      ```
    - Lines 91–95, 178–182: Arbitrary unvalidated Base64 strings are stored directly using `$this->storageService->saveBase64Image(...)`.
    - Lines 135–137: If `verify_status === 1`, it immediately dispatches `\App\Jobs\ProcessAttendancePunchJob::dispatch($log)`.
- **Finding:** Any remote entity can send forged verification payloads to `/Subscribe/Verify` or `/api/Subscribe/Verify`, automatically register rogue devices as active, write unvalidated Base64 files to disk, and forge valid attendance punches without any authentication or rate limits.

---

### SEC-02: Camera Hardware Passwords in Storage & API Serialization
- **Files Inspected:**
  - `app/Models/Device.php`:
    - Lines 30–46: `'password'` is listed in `$fillable`.
    - Lines 48–54 (`$casts`):
      ```php
      protected $casts = [
          'port' => 'integer',
          'device_type' => 'integer',
          'is_active' => 'boolean',
          'last_heartbeat_at' => 'datetime',
          'department_ids' => 'array',
      ];
      ```
      `'password' => 'encrypted'` is NOT present in `$casts`.
    - `protected $hidden` is completely missing from `Device.php`.
  - `app/Http/Controllers/DeviceController.php`:
    - Line 31 (`index` method):
      ```php
      'password' => $device->password,
      ```
      Explicitly serialized and returned in cleartext to anyone listing devices!
    - Line 83 (`store` method): `return response()->json($device, 201);` returns the full model including password.
    - Lines 97–102 (`show` method): `return response()->json(array_merge($device->toArray(), ...))` returns the full model including password.
    - Line 134 (`update` method): `return response()->json($device);` returns the full model including password.
- **Finding:** Camera device admin passwords (used for hardware control and video stream access) are stored in plaintext at rest in PostgreSQL and exposed in cleartext across index, show, store, and update API endpoints.

---

### SEC-03: WAN MQTT TLS & Broker Authentication
- **Files Inspected:**
  - `app/Console/Commands/MqttListenCommand.php` lines 39–47:
    ```php
    $settings = (new ConnectionSettings)
        ->setKeepAliveInterval(60)
        ->setConnectTimeout(10)
        ->setUseTls(false);

    if (env('MQTT_AUTH', false) && env('MQTT_USERNAME')) {
        $settings->setUsername((string) env('MQTT_USERNAME'))
                 ->setPassword((string) env('MQTT_PASSWORD'));
    }
    ```
    TLS is hardcoded to `false`. Authentication is skipped if `MQTT_AUTH` is not explicitly set to true.
  - `app/Services/CameraMqttService.php`:
    - Line 71 (`publishCommandAndWait`): `->setUseTls(false);`
    - Line 222 (`publishCommand`): `->setUseTls(false);`
    Both downlink execution paths hardcode TLS to `false`.
  - `start-dev.sh` lines 74–80:
    ```bash
    # C. Start bore TCP Tunnel for MQTT (using static remote port 35803)
    echo -e "${CYAN}→ Starting bore TCP Tunnel for MQTT...${NC}"
    ./bore local 1883 --to bore.pub --port 35803 > /tmp/bore.log 2>&1 &
    BORE_PID=$!
    ```
    Exposes raw unencrypted MQTT port 1883 publicly via `bore.pub` with no TLS, allowing anyone on the internet to connect to the MQTT broker.
- **Finding:** MQTT traffic containing face telemetry and downlink device commands is transmitted unencrypted over cleartext TCP, and an unauthenticated public tunnel exposes the broker.

---

### SEC-04: Reverb Broadcast Channels Conversion to PrivateChannel
- **Files Inspected:**
  - `app/Events/AccessLogReceived.php` line 23: `new Channel('access-logs')`
  - `app/Events/DeviceAlertReceived.php` lines 23–24: `new Channel('device-alerts')`, `new Channel('alerts')`
  - `app/Events/DeviceAlertUpdated.php` lines 25–26: `new Channel('device-alerts')`, `new Channel('alerts')`
  - `app/Events/StrangerSnapReceived.php` line 23: `new Channel('stranger-snaps')`
  - `app/Events/DeviceStatusUpdated.php` line 23: `new Channel('device-status')`
  - `app/Events/AttendancePunchReceived.php` line 26: `new Channel('attendance')`
  - `app/Events/VisitorCheckedIn.php` line 23: `new Channel('visitors')`
  - `app/Events/VisitorCheckedOut.php` line 23: `new Channel('visitors')`
  - `app/Events/PersonnelUpdated.php` line 27: `new Channel('personnel')`
  - `app/Events/SyncTaskUpdated.php` line 25: `new Channel('sync-tasks')`
  - `app/Events/NotificationCreated.php` line 23: `new Channel('notifications')`
  - `routes/channels.php` lines 5–7: Only defines `App.Models.User.{id}`. No authorization callbacks exist for `access-logs`, `device-alerts`, `stranger-snaps`, `attendance`, `visitors`, `personnel`, or `sync-tasks`.
  - `resources/js/echo.js` lines 12–20: Does not configure `authEndpoint` or `auth.headers.Authorization` for Bearer token authorization.
  - `resources/js/App.vue` lines 754–805: Subscribes using `echo.channel('access-logs')`, `echo.channel('stranger-snaps')`, `echo.channel('device-alerts')`, etc.
- **Finding:** All vision telemetry events (containing face snapshot URLs, employee names, match similarities, and physical security alerts) broadcast over public, unauthenticated WebSocket channels. Any external party connecting to Reverb can sniff all live biometric telemetry.

---

### SEC-05: Server-Side Request Forgery (SSRF) in Image Storage Ingestion
- **Files Inspected:**
  - `app/Services/ImageStorageService.php` lines 157–170 (`storeFromUrlOrPath`):
    ```php
    } elseif (filter_var($urlOrPath, FILTER_VALIDATE_URL)) {
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(8)->get($urlOrPath);
            if ($response->successful()) {
                $binary = $response->body();
                $contentType = $response->header('Content-Type') ?? '';
                if (str_contains($contentType, 'png')) {
                    $extension = 'png';
                }
            }
        } catch (\Throwable $e) {
            // fall through
        }
    }
    ```
  - `app/Http/Controllers/PersonnelController.php`:
    - Lines 76–85 in `store()`: Accepts `photo_url` from client and passes it directly to `$this->storageService->storeFromUrlOrPath($source, 'personnel')`.
    - Lines 128–136 in `update()`: Same unvalidated pass-through.
- **Finding:** Attackers can pass internal URLs (e.g. `http://169.254.169.254/latest/meta-data/` or `http://127.0.0.1:8000/api/...` or internal database/Redis endpoints). The backend fetches the URL, saves the body, encodes it into Base64, and returns it in the API response, achieving full SSRF and data exfiltration.

---

### SEC-06: Missing Guards & BOLA/IDOR on Device Alerts and Notifications
- **Files Inspected:**
  - `routes/api.php` lines 163–167:
    ```php
    Route::get('device-alerts', [\App\Http\Controllers\DeviceAlertController::class, 'index']);
    Route::get('device-alerts/stats', [\App\Http\Controllers\DeviceAlertController::class, 'stats']);
    Route::get('device-alerts/{deviceAlert}', [\App\Http\Controllers\DeviceAlertController::class, 'show']);
    Route::patch('device-alerts/{deviceAlert}/status', [\App\Http\Controllers\DeviceAlertController::class, 'updateStatus']);
    Route::post('device-alerts/bulk-status', [\App\Http\Controllers\DeviceAlertController::class, 'bulkUpdateStatus']);
    ```
    None of these routes have permission middleware! Any logged-in user with any role can dismiss critical security alarms (fire, weapon, intrusion).
  - `app/Http/Controllers/NotificationController.php` lines 31–38 (`markAsRead`):
    ```php
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        DB::table('notifications')
            ->where('id', $id)
            ->update(['read_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => 'Notification marked as read.']);
    }
    ```
    Updates the notification without checking `notifiable_id` or `notifiable_type`. Any authenticated user can mark any other user's notification as read.
- **Finding:** Broken object level authorization (IDOR) on notification updates and missing RBAC permission middleware on critical device alert endpoints.

---

### SEC-07: User-to-Employee Ownership Binding in Self-Service Requests
- **Files Inspected:**
  - `app/Http/Controllers/LeaveController.php`:
    - Line 180: `'employee_id' => 'required'`
    - Lines 208–214: `submitLeaveRequest` uses whatever `employee_id` was supplied.
    - Lines 222–243: `approveRequest` does NOT check whether the approver is the employee themselves (`$leaveRequest->employee->user_id !== $request->user()->id`).
  - `app/Http/Controllers/RegularizationController.php`:
    - Line 38: `'employee_id' => 'required'`
    - Lines 63–70: Submits regularization for any specified `employee_id`.
- **Finding:** Regular employees can submit leave requests or alter punches for any other employee in the organization, and managers can self-approve their own leave requests without separation of duties.

---

### SEC-08: Insecure Dummy Entity Auto-Creation Mock Code in Controllers
- **Files Inspected:**
  - `app/Http/Controllers/RegularizationController.php`:
    - Lines 52–61 (`store`):
      ```php
      $employee = Employee::find($validated['employee_id']);
      if (!$employee) {
          $employee = Employee::create([
              'id' => is_numeric($validated['employee_id']) ? (int) $validated['employee_id'] : 1,
              'employee_code' => 'EMP-' . $validated['employee_id'],
              'first_name' => 'Auto',
              'last_name' => 'Employee',
              'employment_status' => 'active',
          ]);
      }
      ```
    - Lines 80–92 (`approve`):
      ```php
      $regularization = RegularizationRequest::find($id);
      if (!$regularization) {
          $emp = Employee::first() ?? Employee::create(['id' => 1, 'employee_code' => 'EMP-01', 'first_name' => 'Test', 'employment_status' => 'active']);
          $regularization = RegularizationRequest::create([
              'id' => $id,
              'employee_id' => $emp->id,
              'date' => Carbon::yesterday()->toDateString(),
              'requested_in' => Carbon::yesterday()->setHour(9)->setMinute(0),
              'requested_out' => Carbon::yesterday()->setHour(18)->setMinute(0),
              'reason' => 'Turnstile miss',
              'status' => 'pending',
          ]);
      }
      ```
  - `app/Http/Controllers/LeaveController.php`:
    - Lines 123–142 (`allocateBalance`): Auto-creates `Employee` and `LeaveType` if not found.
    - Lines 187–206 (`storeRequest`): Auto-creates `Employee` and `LeaveType` if not found.
  - `app/Http/Controllers/VisitorController.php`:
    - Lines 180–190 (`preRegister`): Auto-creates `Employee` if `host_employee_id` not found.
    - Lines 209–218 (`checkIn`): Auto-creates `Visitor` and `Visit` if `$id` not found.
  - `app/Http/Controllers/AttendanceController.php`:
    - Lines 121–130 (`manualEntry`): Auto-creates `Employee` if `employee_id` not found.
    - Lines 151–167 (`override`): Auto-creates `Employee` and `AttendanceRecord` if `$id` not found.
- **Finding:** Eight separate endpoints automatically inject fake records with arbitrary IDs into PostgreSQL tables upon querying or passing non-existent IDs.

---

### SEC-09: TLS Certificate Verification on Camera HTTP Egress
- **Files Inspected:**
  - `app/Services/CameraHttpService.php`:
    - Lines 132–134 (`postAction`):
      ```php
      if ($scheme === 'https') {
          $httpRequest = $httpRequest->withoutVerifying();
      }
      ```
    - Lines 288–290 (`probeEndpoint`):
      ```php
      if ($curScheme === 'https') {
          $httpRequest = $httpRequest->withoutVerifying();
      }
      ```
- **Finding:** Egress HTTPS requests to camera hardware completely disable TLS verification via `withoutVerifying()`, opening all downlink credentials and personnel face payloads to Man-in-the-Middle (MitM) attacks.

---

### SEC-10: Sanctum API Token Expiration & Pruning
- **Files Inspected:**
  - `config/sanctum.php`: File does not exist. Defaults to `expiration => null`.
  - `routes/console.php` lines 11–13: No scheduled token pruning.
- **Finding:** Personal access tokens issued upon login remain valid indefinitely. Stale or revoked tokens accumulate without automated cleanup.

---

### SEC-11: Protecting Biometric Face Photos & Verification Snaps
- **Files Inspected:**
  - `app/Services/ImageStorageService.php` lines 46–48, 90–91, 110–111, 179–180: All images are saved to `Storage::disk('public')`.
  - `config/filesystems.php` lines 41–48, 76–78: `'public'` disk points to `storage/app/public` symlinked to `public/storage`.
- **Finding:** Biometric facial templates, employee photos, stranger snaps, and surveillance scene images are publicly accessible via direct unauthenticated HTTP URLs (`/storage/personnel/...`, `/storage/snaps/...`).

---

### SEC-12: CSV Formula Injection (DDE) Sanitization
- **Files Inspected:**
  - `app/Http/Controllers/EmployeeController.php` lines 410–423: Outputs `$emp->first_name`, `$emp->last_name`, `$emp->employee_code` directly to `fputcsv()`.
  - `app/Http/Controllers/PayrollExportController.php` lines 94–107: Outputs `$row['employee_code']`, `$row['employee_name']`, `$row['department']` directly to `fputcsv()`.
  - `app/Http/Controllers/ReportController.php` lines 116–128, 150–163: Outputs employee names, visitor names, company names directly to `fputcsv()`.
- **Finding:** Exported CSV streams do not sanitize formula trigger characters (`=`, `+`, `-`, `@`, `\t`, `\r`), allowing CSV / formula injection (DDE execution) in spreadsheet applications.

---

### SEC-13: Security Headers & API Rate Limiting
- **Files Inspected:**
  - `bootstrap/app.php` lines 17–28: No security headers middleware is registered.
  - `routes/api.php` lines 48–52: No global rate limiting (`throttle:api`) attached to authenticated API endpoints.
  - `app/Providers/AppServiceProvider.php` lines 20–26: No rate limiter defined for `'api'`.
- **Finding:** HTTP responses lack standard security headers (`X-Frame-Options`, `X-Content-Type-Options`, `Strict-Transport-Security`, `Content-Security-Policy`), and API routes lack rate limiting.

---

### SEC-14: Dependency Audit
- **Command Executions:**
  - `npm audit --json`:
    - Identified 12 advisories for `axios` (versions `>=1.0.0 <1.20.0`), including high-severity Prototype Pollution (GHSA-vh66-26gq-q6x8, GHSA-x97p-jq2g-jp4f), ReDoS (GHSA-c29m-xwm3-cm6r, GHSA-mghh-pgcx-3jjj), and HTTP/2 adapter bypasses (GHSA-3pq3-5fj3-cg6v).
  - `composer audit --format=json`:
    - Identified advisories for:
      - `laravel/framework` (`CVE-2026-102279`: XSS in Debug Page Information)
      - `league/commonmark` (`GHSA-97jj-33gv-5xf9`, `GHSA-3q6v-r5mr-hxv8`: ReDoS / DisallowedRawHtml bypass)
      - `league/flysystem` (`CVE-2026-102601`: WhitespacePathNormalizer bypass)
- **Finding:** Upstream dependencies contain published vulnerabilities that require updates in `package.json` and `composer.json`.

---

### SEC-15: `.env.example` Application Key Sanitization
- **Files Inspected:**
  - `.env.example` line 3:
    ```env
    APP_KEY=base64:2uDSBwXABxqKrme22nL1rR4Acnranb/7QN9hBStcbM8=
    ```
- **Finding:** A hardcoded AES-256 base64 application key exists in the `.env.example` template.

---

## 2. Logic Chain

1. **Camera Webhook Vulnerability (SEC-01):**
   - Observation 1.1 shows `/Subscribe/*` endpoints in `routes/api.php` and `routes/web.php` have no authentication or throttling.
   - Observation 1.2 shows `HttpWebhookController` executes `Device::firstOrCreate(...)` on unauthenticated requests.
   - Therefore, any unauthenticated attacker can register rogue devices, flood disks with base64 payloads, or inject fake punches into `ProcessAttendancePunchJob`.
   - Remediating this requires validating a shared secret header or matching device credentials, rejecting unknown devices, validating base64 limits, and applying `throttle:60,1`.

2. **Cleartext Password Storage & Leakage (SEC-02):**
   - Observation 2.1 shows `Device.php` lacks `$hidden = ['password']` and lacks `'password' => 'encrypted'` in `$casts`.
   - Observation 2.2 shows `DeviceController::index()` explicitly outputs `'password' => $device->password`.
   - Therefore, database theft or API inspection exposes all hardware credentials.
   - Remediating this requires adding `$hidden` and cast encryption in `Device.php`, and removing `'password'` from `DeviceController` responses.

3. **Insecure WAN Transport (SEC-03 & SEC-09):**
   - Observation 3.1 & 9.1 show `MqttListenCommand`, `CameraMqttService`, and `CameraHttpService` disable TLS (`setUseTls(false)`, `withoutVerifying()`).
   - Observation 3.2 shows `start-dev.sh` tunnels raw MQTT port 1883 over `bore.pub`.
   - Therefore, telemetry and credentials traversing WAN/cellular links are vulnerable to MitM and public eavesdropping.
   - Remediating this requires enabling TLS support with CA bundle options and shutting down insecure tunnels.

4. **Telemetry Snooping via Reverb (SEC-04):**
   - Observation 4.1 shows all 11 event classes broadcast to public `new Channel(...)` and `routes/channels.php` lacks channel guards.
   - Therefore, unauthorized observers can read all live camera feeds, faces, and punches without logging in.
   - Remediating this requires converting events to `PrivateChannel`, adding authorization callbacks in `routes/channels.php`, and configuring `auth:sanctum` headers in `echo.js`.

5. **SSRF via Image Ingestion (SEC-05):**
   - Observation 5.1 shows `ImageStorageService::storeFromUrlOrPath` performs `Http::get($urlOrPath)` with no IP, host, or protocol validation.
   - Observation 5.2 shows `PersonnelController` feeds user-supplied `photo_url` into this method and returns the Base64 result.
   - Therefore, attackers can probe internal network endpoints or exfiltrate cloud metadata.
   - Remediating this requires DNS IP resolution checks, blocking RFC 1918 and loopback/metadata IPs, and restricting redirects.

6. **Missing Authorization & IDOR (SEC-06 & SEC-07):**
   - Observation 6.1 shows `routes/api.php` omits permission middleware on `device-alerts` endpoints.
   - Observation 6.2 shows `NotificationController::markAsRead` updates records without scoping to `notifiable_id`.
   - Observation 7.1 shows `LeaveController` and `RegularizationController` allow users to submit requests for arbitrary `employee_id`s and approve their own requests.
   - Therefore, users can tamper with alerts, read/update other users' notifications, alter others' attendance, or self-approve leaves.
   - Remediating this requires attaching `permission` middleware, enforcing ownership checks, and forbidding self-approvals.

7. **Database Corruption via Mock Auto-Creation (SEC-08):**
   - Observation 8.1 shows eight controller actions instantiate dummy records when an ID lookup fails.
   - Therefore, probing non-existent IDs corrupts production database state.
   - Remediating this requires replacing mock creates with `findOrFail($id)` or 404 responses.

8. **Session Lifetime & Key Hygiene (SEC-10 & SEC-15):**
   - Observation 10.1 shows `config/sanctum.php` is missing (tokens never expire) and token pruning is not scheduled.
   - Observation 15.1 shows a hardcoded `APP_KEY` in `.env.example`.
   - Remediating this requires configuring `expiration` in `config/sanctum.php`, scheduling `sanctum:prune-expired`, and setting `APP_KEY=` in `.env.example`.

9. **Data Protection: Biometric Storage & CSV Injection (SEC-11, SEC-12, SEC-13, SEC-14):**
   - Observations 11.1, 12.1, 13.1, 14.1 show public storage of facial media, unescaped CSV formulas, missing security headers, and known dependency CVEs.
   - Remediating this requires private disk storage/streaming, CSV trigger escaping, security headers middleware, and running dependency updates.

---

## 3. Caveats

1. **Hardware Camera Constraints on Webhooks:** Some legacy edge cameras do not support custom HTTP headers (such as `X-Camera-Secret`). For such models, authentication should support HTTP Basic Authentication (matching device credentials) or IP allowlisting matching `devices.ip_address`.
2. **Reverb Private Channels & Existing Tests:** Unit tests that assert broadcast events using `Event::fake()` will verify the event dispatch regardless of channel type, but feature tests verifying channel authorization must ensure the test user has the requisite permissions.
3. **SSRF Validation in Local Development:** If developers use local mock image URLs during testing, the IP check must distinguish between local test suites (where mock URLs may be faked using `Http::fake()`) and live network requests.

---

## 4. Conclusion & Actionable Blueprint

All 15 security remediation tasks in `tasks-security.md` are substantiated by direct codebase evidence. The exact files, modifications, and testing requirements are organized below for immediate execution:

| Task ID | Severity | Primary Target Files | Core Modification Required |
|---|---|---|---|
| **SEC-01** | CRITICAL | `routes/api.php`<br>`routes/web.php`<br>`HttpWebhookController.php` | Add camera secret/basic auth verification; reject unapproved devices (remove `firstOrCreate`); rate limit with `throttle:60,1`; validate base64 size. |
| **SEC-02** | CRITICAL | `app/Models/Device.php`<br>`DeviceController.php` | Add `protected $hidden = ['password'];` and `'password' => 'encrypted'` cast; remove `'password'` from `DeviceController` responses. |
| **SEC-03** | CRITICAL | `MqttListenCommand.php`<br>`CameraMqttService.php`<br>`start-dev.sh` | Enable configurable TLS (`setUseTls(env('MQTT_TLS'))`); enforce broker auth; disable insecure `bore.pub` tunnel script. |
| **SEC-04** | CRITICAL | `app/Events/*.php` (11 events)<br>`routes/channels.php`<br>`echo.js`<br>`App.vue` | Convert `Channel` to `PrivateChannel`; define channel callbacks in `routes/channels.php`; configure Bearer auth in `echo.js`; update Vue listeners to `echo.private()`. |
| **SEC-05** | HIGH | `ImageStorageService.php`<br>`PersonnelController.php` | Add DNS IP validation against RFC 1918, loopback, and metadata IPs (`169.254.169.254`); disable redirects; validate MIME type. |
| **SEC-06** | HIGH | `routes/api.php`<br>`DeviceAlertController.php`<br>`NotificationController.php` | Attach `permission:devices.manage` to alert routes; scope `markAsRead` to `$user->id`. |
| **SEC-07** | HIGH | `LeaveController.php`<br>`RegularizationController.php` | Force non-manager `employee_id` to current user's employee; forbid self-approval on leave requests. |
| **SEC-08** | HIGH | `RegularizationController.php`<br>`LeaveController.php`<br>`VisitorController.php`<br>`AttendanceController.php` | Remove all fallback `Model::create(['id' => $id, ...])` mock code; use `findOrFail($id)` or return 404. |
| **SEC-09** | HIGH | `CameraHttpService.php` | Remove `withoutVerifying()`; configure CA bundle verification and configurable TLS check. |
| **SEC-10** | MEDIUM | `config/sanctum.php`<br>`routes/console.php` | Publish `config/sanctum.php` with `'expiration' => env('SANCTUM_TOKEN_EXPIRATION', 1440)`; schedule `sanctum:prune-expired` daily in `routes/console.php`. |
| **SEC-11** | MEDIUM | `ImageStorageService.php`<br>`config/filesystems.php`<br>`routes/api.php` | Store face templates and verification snaps on private disk; implement authenticated streaming endpoint with permission checks. |
| **SEC-12** | MEDIUM | `EmployeeController.php`<br>`PayrollExportController.php`<br>`ReportController.php` | Escape formula trigger characters (`=`, `+`, `-`, `@`, `\t`, `\r`) before passing strings to `fputcsv()`. |
| **SEC-13** | MEDIUM | `bootstrap/app.php`<br>`routes/api.php`<br>`AppServiceProvider.php` | Add security headers middleware (HSTS, CSP, X-Frame-Options, nosniff); apply `throttle:api` to Tier 3 routes. |
| **SEC-14** | MEDIUM | `package.json`<br>`composer.json` | Upgrade `axios` to `^1.21.0`; run `composer update league/commonmark laravel/framework league/flysystem`. |
| **SEC-15** | LOW | `.env.example` | Set `APP_KEY=` (empty string). |

---

## 5. Verification Method

To independently verify the implementation of all 15 tasks:

1. **Automated Test Suite Regression & Security Testing:**
   ```bash
   php artisan test
   ```
   *Expected Result:* All existing 264 tests pass, plus new security test cases verifying:
   - Webhook authentication rejection (401/403) and rate limiting (429).
   - Hidden and encrypted password behavior in `Device` model.
   - Private channel authorization checks on `/broadcasting/auth`.
   - SSRF rejection for private/metadata IPs in `ImageStorageService`.
   - 403 on self-approval of leaves and cross-user regularization submissions.
   - 404 instead of dummy entity auto-creation for missing IDs.
   - CSV formula prefix escaping.

2. **Route & Channel Inspection:**
   ```bash
   php artisan route:list --path=api/device-alerts
   php artisan route:list --path=Subscribe
   php artisan channel:list
   ```
   *Expected Result:* Permission middleware (`devices.view`, `devices.manage`) is attached to `device-alerts`, `throttle:60,1` is attached to `Subscribe/*`, and private channels are registered in `channel:list`.

3. **Dependency Vulnerability Audits:**
   ```bash
   npm audit
   composer audit
   ```
   *Expected Result:* Zero high or critical vulnerabilities reported.

4. **Frontend Asset Build Verification:**
   ```bash
   npm run build
   ```
   *Expected Result:* Clean compilation of Vue 3 SPA with updated `echo.private(...)` subscriptions.

5. **Invalidation Conditions:**
   - Any API response exposing cleartext device passwords invalidates SEC-02.
   - Any unauthenticated client connecting to WebSocket telemetry channels without Sanctum authentication invalidates SEC-04.
   - Any request to `/Subscribe/*` resulting in an untrusted record added to `devices` table invalidates SEC-01.
   - Any HTTP request reaching `169.254.169.254` via `ImageStorageService` invalidates SEC-05.
