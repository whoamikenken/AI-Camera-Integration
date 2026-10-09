# Forensic Audit Report — Milestone 2: Edge Ingestion & Input Security (SEC-13, SEC-15, SEC-16, SEC-19)

**Auditor:** Forensic Auditor (`auditor_m2_1`)  
**Date:** 2026-10-07T07:20:00Z  
**Target Milestone:** Milestone 2 (SEC-13, SEC-15, SEC-16, SEC-19)  
**Profile:** General Project  
**Integrity Mode:** Development (per `ORIGINAL_REQUEST.md`)  
**Verdict:** **CLEAN**

---

## 1. Observation

### 1.1 Source Code and Configuration Analysis

1. **`start-dev.sh` (Line 85):**
   ```bash
   if [ "${ENABLE_INSECURE_MQTT_TUNNEL:-false}" = "true" ]; then
   ```
   Directly observed that the public `bore` TCP tunnel (exposing port 1883 to `bore.pub:35803`) now defaults to disabled unless explicitly set to `"true"`.
2. **`.env.example` (Line 56) & `.env` (Line 82):**
   ```env
   ENABLE_INSECURE_MQTT_TUNNEL=false
   ```
   Directly observed that `.env.example` documents `ENABLE_INSECURE_MQTT_TUNNEL=false` and `.env` explicitly configures `ENABLE_INSECURE_MQTT_TUNNEL=false`.
3. **`app/Console/Commands/MqttListenCommand.php`:**
   - Lines 98–102 in `handleMessage()`:
     ```php
     if ($deviceModel && $deviceModel->is_active) {
         $deviceModel->update([
             'last_heartbeat_at' => now(),
         ]);
     }
     ```
     Observed that incoming messages no longer force `'is_active' => true` on disabled devices.
   - Lines 244–257 in `handleVerifyPush()`, lines 344–357 in `handleStrangerSnapPush()`, lines 431–444 in `handleDeviceAlert()`:
     ```php
     $device = Device::where('device_id', $deviceId)->first();
     if (!$device || !$device->is_active) {
         Log::warning("VerifyPush dropped: Device [{$deviceId}] is not enrolled or inactive.");
         if (!$device) {
             Device::create([
                 'device_id' => $deviceId,
                 'name' => "Camera {$deviceId}",
                 'ip_address' => '192.168.1.100',
                 'is_active' => false,
                 'last_heartbeat_at' => now(),
             ]);
         }
         return;
     }
     ```
     Observed that rogue un-enrolled devices are staged with `is_active = false`, and telemetry packets from un-enrolled or deactivated devices are dropped before access log insertion, attendance job dispatch, or WebSocket broadcasting.
   - Lines 625–639 in `handleHeartbeat()` & lines 649–679 in `handleOnlineStatus()`:
     Observed `firstOrCreate` sets `is_active => false`, heartbeat updates only `last_heartbeat_at`, and WebSockets broadcast `DeviceStatusUpdated` strictly if `$device->is_active === true`.
4. **`app/Http/Controllers/PersonnelController.php`:**
   - Lines 68 & 130:
     ```php
     'photo' => 'nullable|file|mimes:jpeg,jpg,png,webp|max:10240',
     ```
     Observed that biometric photo uploads require raster types (`jpeg,jpg,png,webp`), disallowing vector/SVG files.
   - Lines 85–93 & 147–155:
     ```php
     foreach (['photo_url', 'photo_path'] as $field) {
         if (!empty($validated[$field]) && filter_var($validated[$field], FILTER_VALIDATE_URL)) {
             if (!$this->storageService->isSafeUrl($validated[$field])) {
                 throw \Illuminate\Validation\ValidationException::withMessages([
                     $field => ['The provided photo URL points to a restricted or private network address.'],
                 ]);
             }
         }
     }
     ```
     Observed that anti-SSRF validation is applied symmetrically across both `photo_url` and `photo_path`.
5. **`app/Services/ImageStorageService.php`:**
   - Lines 326–338 in `getMedia()`:
     ```php
     $ext = strtolower(pathinfo($cleanPath, PATHINFO_EXTENSION));
     if (in_array($ext, ['svg', 'xml', 'html', 'htm'], true)) {
         return null;
     }
     ...
     if (str_contains(strtolower($mimeType), 'svg') || str_contains(strtolower($mimeType), 'xml') || str_contains(strtolower($mimeType), 'html')) {
         return null;
     }
     ```
     Observed that vector and markup files are refused and return `null` (yielding HTTP 404), preventing Stored XSS via media streaming.
6. **`app/Http/Controllers/HttpWebhookController.php` (Line 61):**
   ```php
   if ($clientIp === $device->ip_address || (app()->environment('local', 'testing') && in_array($clientIp, ['127.0.0.1', '::1'], true))) {
   ```
   Observed that loopback IP bypass is restricted to local/testing environments.
7. **`bootstrap/app.php` (Line 18):**
   ```php
   $middleware->trustProxies(at: '*');
   ```
   Observed that proxy headers (`X-Forwarded-For`) are trusted to resolve real client IP addresses.

### 1.2 Empirical Test Execution Results

- `php artisan test --filter=SecurityRemediationTest`:
  ```json
  {"tool":"phpunit","result":"passed","tests":29,"passed":29,"assertions":112,"duration_ms":793}
  ```
- `php artisan test --filter=TelemetryDeduplicationTest`:
  ```json
  {"tool":"phpunit","result":"passed","tests":3,"passed":3,"assertions":8,"duration_ms":258}
  ```
- `php artisan test --filter="SecurityAdversarialGateTest|MediaAccessAndUnauthenticatedRouteTest|PersonnelSyncTest|HttpProtocolV113Test"`:
  ```json
  {"tool":"phpunit","result":"passed","tests":44,"passed":44,"assertions":275,"duration_ms":61403}
  ```
- `php artisan test --filter=PerformanceOptimizationTest`:
  ```json
  {"tool":"phpunit","result":"passed","tests":26,"passed":26,"assertions":216,"duration_ms":671}
  ```

### 1.3 Adversarial Stress Testing Results (via Artisan Tinker)

1. **SSRF Filter (`isSafeUrl`) Against Hostile Targets:**
   - `http://127.0.0.1` -> `bool(false)`
   - `http://169.254.169.254` (AWS/GCP metadata) -> `bool(false)`
   - `http://localhost` -> `bool(false)`
   - `file:///etc/passwd` -> `bool(false)`
   - `javascript:alert(1)` -> `bool(false)`
   - `http://10.0.0.1` -> `bool(false)`
   - `http://192.168.1.1` -> `bool(false)`
   - `https://example.com/image.jpg` -> `bool(true)`
2. **Disguised SVG File Upload Validation:**
   - File with content `<svg...><script>alert(1)</script></svg>` named `test.jpg` with client MIME `image/jpeg`:
     `$validator->fails()` -> `bool(true)` (`The photo field must be a file of type: jpeg, jpg, png, webp.`)
   - Genuine JPEG binary: `$validator->fails()` -> `bool(false)`.
3. **Disguised SVG in Media Storage (`getMedia`):**
   - File containing SVG markup stored as `personnel/disguised.jpg`:
     `$storageService->getMedia('personnel/disguised.jpg')` -> `NULL`.
   - Genuine JPEG stored as `personnel/valid.jpg`:
     `$storageService->getMedia('personnel/valid.jpg')` -> `array(content, mime_type: "image/jpeg")`.

---

## 2. Logic Chain

1. *From 1.1.1 & 1.1.2:* `start-dev.sh` and configuration files now default `ENABLE_INSECURE_MQTT_TUNNEL=false`, closing the unauthenticated WAN MQTT exposure on `bore.pub:35803`.
2. *From 1.1.3:* `MqttListenCommand` inspects device enrollment and `is_active` status before processing incoming telemetry. Rogue devices are staged as inactive and their telemetry discarded. Deactivated devices cannot be reactivated by incoming packets or heartbeats.
3. *From 1.1.4, 1.1.5 & 1.3.2, 1.3.3:* `PersonnelController` enforces `mimes:jpeg,jpg,png,webp`, and `ImageStorageService::getMedia()` inspects file extension and detected MIME types. Even if an attacker renames an SVG file to `.jpg`, both upload validation and media streaming detect XML/SVG signatures and reject the payload, eliminating Stored XSS.
4. *From 1.1.4 & 1.3.1:* Symmetrically checking `photo_url` and `photo_path` against `isSafeUrl()` prevents attackers from circumventing SSRF filtering by providing cloud metadata or loopback addresses in `photo_path`. Valid relative storage paths evaluate to `false` under `filter_var(..., FILTER_VALIDATE_URL)` and remain allowed.
5. *From 1.1.6 & 1.1.7:* Restricting loopback IP bypass in `HttpWebhookController` to `local` and `testing` environments, coupled with `$middleware->trustProxies(at: '*')`, prevents spoofed authentication behind reverse proxies in production while maintaining development ergonomics.
6. *From 1.2:* All unit, feature, and adversarial test suites for Milestone 2 pass cleanly with zero failures and zero errors.

---

## 3. Caveats

- **Concurrent Workspace Activity (Milestone 3):** During the full test suite run (`php artisan test`), two failures occurred in `Tier1FeatureCoverageTest::test_f10_sync_personnel_job_dispatches_only_to_authorized_devices` and `Tier3CrossFeatureTest::test_cross_access_control_scopes_personnel_synchronization_to_zone`. Forensic investigation determined that these tests belong to Feature 1 / Milestone 3 (Access Control Groups), which is currently in-progress by `worker_m3` in the shared workspace (untracked file `app/Services/AccessControlService.php` and migration `database/migrations/2026_10_07_000002_create_access_groups_table.php`). None of the 10 files in Milestone 2's scope caused or contributed to these failures.
- **Task Checklist:** `tasks-security.md` lines 13, 15, 16, 19 remain currently unchecked `[ ]` awaiting final orchestrator milestone sign-off.
- No other caveats.

---

## 4. Conclusion

The Milestone 2 work products satisfy all requirements of SEC-13, SEC-15, SEC-16, and SEC-19:
- No hardcoded test outputs, facade methods, or fabricated verification artifacts were found.
- Implementations are genuine, robust, and verified empirically against both standard and adversarial inputs.
- The work product is declared **CLEAN** and accepted.

---

## 5. Verification Method

To independently verify this forensic assessment:

1. **Verify SEC-13, SEC-15, SEC-16, SEC-19 Test Suite:**
   ```bash
   php artisan test --filter=SecurityRemediationTest
   ```
   *Expected:* 29 passed, 112 assertions, 0 failures.

2. **Verify Telemetry Deduplication Suite:**
   ```bash
   php artisan test --filter=TelemetryDeduplicationTest
   ```
   *Expected:* 3 passed, 8 assertions, 0 failures.

3. **Verify Adversarial Edge Cases in Tinker:**
   ```bash
   php artisan tinker --execute="\$s = app(App\Services\ImageStorageService::class); var_dump(\$s->isSafeUrl('http://169.254.169.254'), \$s->isSafeUrl('http://127.0.0.1'));"
   ```
   *Expected:* `bool(false)`, `bool(false)`.

4. **Inspect Files for Absence of Facades or Mock Shortcuts:**
   - `start-dev.sh` (line 85)
   - `.env.example` (line 56)
   - `app/Console/Commands/MqttListenCommand.php` (lines 98, 244, 344, 431, 625, 649)
   - `app/Http/Controllers/PersonnelController.php` (lines 68, 85, 130, 147)
   - `app/Services/ImageStorageService.php` (lines 326–338)
   - `app/Http/Controllers/HttpWebhookController.php` (line 61)
   - `bootstrap/app.php` (line 18)
