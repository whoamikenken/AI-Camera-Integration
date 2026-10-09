# Handoff Report — Milestone 2: Edge Ingestion & Input Security (SEC-13, SEC-15, SEC-16, SEC-19)

**Author:** Worker Milestone 2  
**Date:** 2026-10-07T07:12:00Z  
**Recipient:** Orchestrator (71aec755-ccf7-4da2-9035-e66085685b0c)  
**Status:** Hard Handoff (Completed)

---

## 1. Observation

### 1.1 SEC-13: Insecure WAN MQTT Tunnel & Rogue Device Ingestion
- **File:** `start-dev.sh`:
  - Previously at lines 84–92: `${ENABLE_INSECURE_MQTT_TUNNEL:-true} != "false"` defaulted to starting the `bore` TCP tunnel (`bore.pub:35803`) exposing the unencrypted MQTT broker (port 1883) publicly.
  - Modified to: `if [ "${ENABLE_INSECURE_MQTT_TUNNEL:-false}" = "true" ]; then`, ensuring the tunnel is disabled by default.
- **File:** `.env.example`:
  - Previously omitted `ENABLE_INSECURE_MQTT_TUNNEL`.
  - Added `ENABLE_INSECURE_MQTT_TUNNEL=false` under the MQTT broker configuration section.
- **File:** `.env`:
  - Previously set `ENABLE_INSECURE_MQTT_TUNNEL=true` at line 82.
  - Modified to `ENABLE_INSECURE_MQTT_TUNNEL=false`.
- **File:** `app/Console/Commands/MqttListenCommand.php`:
  - `handleMessage()` (lines 95–105): Previously set `'is_active' => true` on any incoming packet for an existing device, overriding administrator disabling of compromised devices. Now updates `'last_heartbeat_at' => now()` only if `$deviceModel && $deviceModel->is_active`.
  - `handleVerifyPush()` (lines 240–265): Previously called `Device::firstOrCreate(..., ['is_active' => true])`. Now queries `Device::where('device_id', $deviceId)->first()`. If not found or `!$device->is_active`, drops the verification telemetry immediately. If un-enrolled, stages the camera with `'is_active' => false` (matching `HttpWebhookController::handleHeartbeat`), but does NOT process or broadcast.
  - `handleStrangerSnapPush()` (lines 340–365): Applies identical device enrollment verification and staging; drops un-enrolled/inactive stranger snaps without database record creation or WebSocket broadcast.
  - `handleDeviceAlert()` (lines 425–450): Applies identical device enrollment verification and staging; drops alerts from un-enrolled/inactive cameras.
  - `handleHeartbeat()` (lines 620–640) & `handleOnlineStatus()` (lines 645–680): If the device is not found, creates it with `'is_active' => false`. Only updates `last_heartbeat_at` and only broadcasts `DeviceStatusUpdated` if `$device->is_active === true`. Console output calls are guarded with `if ($this->output)`.
- **File:** `tests/Feature/TelemetryDeduplicationTest.php`:
  - Pre-enrolled camera `1026230` with `is_active => true` in `test_mqtt_listen_deduplicates_stranger_snaps` and `test_mqtt_listen_deduplicates_verify_push` so test telemetry packets are processed.

### 1.2 SEC-15: Biometric File Upload MIME Types & Disallowing SVG / Stored XSS
- **File:** `app/Http/Controllers/PersonnelController.php`:
  - In `store()` (line 68) and `update()` (line 127): Replaced `'photo' => 'nullable|image|max:10240'` with `'photo' => 'nullable|file|mimes:jpeg,jpg,png,webp|max:10240'`. This restricts biometric file uploads to raster formats and rejects vector XML/SVG files.
- **File:** `app/Services/ImageStorageService.php`:
  - In `getMedia()` (lines 323–340): Added extension checking (`in_array($ext, ['svg', 'xml', 'html', 'htm'], true)`) and MIME type inspection (`str_contains(strtolower($mimeType), 'svg') || str_contains(strtolower($mimeType), 'xml') || str_contains(strtolower($mimeType), 'html')`), returning `null` to prevent serving active web/vector content.

### 1.3 SEC-16: Enforce Symmetric SSRF Protection on `photo_path` in `PersonnelController`
- **File:** `app/Http/Controllers/PersonnelController.php`:
  - In `store()` and `update()`: Symmetrically checked both `photo_url` and `photo_path`. If either input is present and evaluates to a URL via `filter_var(..., FILTER_VALIDATE_URL)`, it is validated using `$this->storageService->isSafeUrl($validated[$field])`. If unsafe (loopback, RFC 1918 private IP, cloud metadata `169.254.169.254`), a `ValidationException` is immediately thrown. Valid relative file paths (e.g., `strangers/test.jpg`) bypass `FILTER_VALIDATE_URL` and remain permitted.

### 1.4 SEC-19: Prevent Reverse-Proxy Loopback IP Authentication Bypass in Webhooks
- **File:** `app/Http/Controllers/HttpWebhookController.php`:
  - In `authenticateWebhook()` (line 61): Restricted the `127.0.0.1` / `::1` client IP bypass check to non-production environments:
    `if ($clientIp === $device->ip_address || (app()->environment('local', 'testing') && in_array($clientIp, ['127.0.0.1', '::1'], true)))`.
- **File:** `bootstrap/app.php`:
  - In `->withMiddleware(function (Middleware $middleware): void {`: Added `$middleware->trustProxies(at: '*');` so reverse proxy headers (`X-Forwarded-For`) resolve the real edge client IP correctly.

### 1.5 Automated Test Execution Results
- `php artisan test --filter=SecurityRemediationTest`: 29 tests passed, 112 assertions, 0 errors, 0 failures.
- `php artisan test --filter=TelemetryDeduplicationTest`: 3 tests passed, 8 assertions, 0 errors, 0 failures.
- `php artisan test --filter=SecurityAdversarialGateTest`: 16 tests passed, 167 assertions, 0 errors, 0 failures.
- `php artisan test --filter=MediaAccessAndUnauthenticatedRouteTest`: 8 tests passed, 43 assertions, 0 errors, 0 failures.
- `php artisan test --filter=PersonnelSyncTest`: 4 tests passed, 7 assertions, 0 errors, 0 failures.
- `php artisan test --filter=HttpProtocolV113Test`: 16 tests passed, 58 assertions, 0 errors, 0 failures.
- Full project test suite `php artisan test`: 520 tests (458 passed, 62 skipped, 0 failed, 0 errors, 2291 assertions).

---

## 2. Logic Chain

1. *From 1.1:* `start-dev.sh` and `.env` previously enabled a public tunnel exposing port 1883 over `bore.pub`, and `MqttListenCommand` auto-created active devices for incoming packets. By defaulting `ENABLE_INSECURE_MQTT_TUNNEL=false` and enforcing that `MqttListenCommand` only processes packets from pre-enrolled active devices (staging unknown devices as `is_active = false`), unauthenticated WAN attackers cannot inject fake attendance punches or active devices.
2. *From 1.2:* Laravel's default `image` rule includes `image/svg+xml`. SVGs allow embedded JavaScript (`<script>` or event handlers), posing a Stored XSS threat when served in the application origin via `/api/media/{path}`. Furthermore, facial recognition hardware requires raster images. Restricting the upload validator to `mimes:jpeg,jpg,png,webp` and having `ImageStorageService::getMedia()` return `null` for SVG/XML/HTML files eliminates the Stored XSS vector entirely.
3. *From 1.3:* When an attacker passed an SSRF target (e.g. `169.254.169.254`) in `photo_path` rather than `photo_url`, `PersonnelController` skipped the `ValidationException`. Iterating over `['photo_url', 'photo_path']` and validating every URL with `isSafeUrl()` closes this loophole while preserving local relative file paths.
4. *From 1.4:* When running behind an upstream reverse proxy without `trustProxies(at: '*')`, `$request->ip()` evaluated to `127.0.0.1`. In production, an unconditional `in_array($clientIp, ['127.0.0.1', '::1'])` check would authenticate any external webhook request. Restricting loopback acceptance to `app()->environment('local', 'testing')` and enabling trusted proxy resolution prevents reverse-proxy authentication bypass.
5. *From 1.5:* All 9 new automated security regression tests and the entire 520-test project suite pass cleanly, confirming robust security posture without regressions.

---

## 3. Caveats

- **Reverse Proxy Headers in Production:** `trustProxies(at: '*')` trusts all reverse proxies. In production setups with specific private proxy IP subnets (e.g. AWS VPC CIDR or Cloudflare IP ranges), administrators may optionally restrict `at:` to specific proxy subnets if desired.
- **Local Development Edge Testing:** In local/testing environments, camera testing from `127.0.0.1` continues to function seamlessly due to `app()->environment('local', 'testing')`.
- No other caveats.

---

## 4. Conclusion

All requirements for Milestone 2 (SEC-13, SEC-15, SEC-16, SEC-19) are fully implemented, verified, and backed by automated test coverage:
- Insecure MQTT tunnel is disabled by default, and rogue devices cannot inject telemetry.
- Personnel photo uploads reject SVG files; media streaming blocks vector/script formats.
- SSRF checks apply symmetrically to `photo_url` and `photo_path`.
- Webhook loopback authentication bypass is locked down to local/testing, with trusted proxies enabled.
- All unit and feature tests pass with zero regressions.

---

## 5. Verification Method

To independently reproduce and verify this work:

1. **Run Dedicated Security Remediation Tests:**
   ```bash
   php artisan test --filter=SecurityRemediationTest
   ```
   Asserts 29 tests pass including:
   - `test_sec13_insecure_mqtt_tunnel_disabled_by_default`
   - `test_sec13_mqtt_listen_drops_telemetry_from_unregistered_or_inactive_device`
   - `test_sec13_mqtt_listen_heartbeat_stages_unknown_device_as_inactive_and_does_not_reactivate`
   - `test_sec15_personnel_photo_upload_rejects_svg_files`
   - `test_sec15_image_storage_service_get_media_rejects_svg_xml_html`
   - `test_sec16_personnel_photo_path_rejects_ssrf_urls`
   - `test_sec16_personnel_photo_path_allows_valid_relative_paths`
   - `test_sec19_webhook_rejects_loopback_ip_bypass_in_production`
   - `test_sec19_trusted_proxies_configured`

2. **Run Telemetry Deduplication Tests:**
   ```bash
   php artisan test --filter=TelemetryDeduplicationTest
   ```
   Asserts 3 tests pass.

3. **Run Full Test Suite:**
   ```bash
   php artisan test
   ```
   Asserts all feature and unit tests pass with 0 failures and 0 errors.

4. **Inspect Code Files:**
   - `start-dev.sh` (line 85)
   - `.env.example` (line 57)
   - `.env` (line 82)
   - `app/Console/Commands/MqttListenCommand.php`
   - `app/Http/Controllers/PersonnelController.php`
   - `app/Services/ImageStorageService.php`
   - `app/Http/Controllers/HttpWebhookController.php`
   - `bootstrap/app.php`
   - `tests/Feature/TelemetryDeduplicationTest.php`
   - `tests/Feature/SecurityRemediationTest.php`
