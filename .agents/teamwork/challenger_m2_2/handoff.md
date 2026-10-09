# Challenger Handoff Report — Milestone 2 (SEC-13, SEC-15, SEC-16, SEC-19)

**Author:** Challenger 2 (Milestone 2)  
**Date:** 2026-10-07T07:27:00Z  
**Recipient:** Orchestrator (71aec755-ccf7-4da2-9035-e66085685b0c)  
**Status:** Hard Handoff (Completed)  
**Verdict:** **APPROVE**

---

## 1. Observation

### 1.1 Empirical Test Suite Execution
- **Command:** `php artisan test --filter="SecurityRemediationTest|Milestone2AdversarialStressTest|TelemetryDeduplicationTest"`
- **Result:**
  ```json
  {"tool":"phpunit","result":"passed","tests":46,"passed":46,"assertions":257,"duration_ms":11302}
  ```
  All 46 tests across regression and adversarial stress suites passed cleanly with 0 failures and 0 errors.

- **Command:** `php artisan test --filter=Milestone2AdversarialStressTest`
- **Result:**
  ```json
  {"tool":"phpunit","result":"passed","tests":14,"passed":14,"assertions":137,"duration_ms":10763}
  ```
  All 14 dedicated adversarial challenge probes passed with 137 assertions.

- **Command:** `npm run build`
- **Result:**
  ```
  vite v8.2.2 building client environment for production...
  ✓ 138 modules transformed.
  ✓ built in 756ms
  ```
  Zero frontend compilation or bundle errors.

### 1.2 SEC-13: Insecure WAN MQTT Tunnel & Rogue Device Ingestion Observations
- **File:** `start-dev.sh` (lines 84–92):
  ```bash
  # C. Public bore TCP Tunnel for MQTT (disabled by default unless ENABLE_INSECURE_MQTT_TUNNEL=true)
  if [ "${ENABLE_INSECURE_MQTT_TUNNEL:-false}" = "true" ]; then
      echo -e "${RED}⚠ WARNING: Exposing unencrypted MQTT to public bore.pub tunnel...${NC}"
      ./bore local 1883 --to bore.pub --port 35803 > /tmp/bore.log 2>&1 &
  ```
  Verified: Unencrypted public TCP tunnel is disabled by default unless explicitly set to `"true"`.
- **File:** `.env.example` (line 57) & `.env` (line 82):
  `ENABLE_INSECURE_MQTT_TUNNEL=false` is enforced.
- **File:** `app/Console/Commands/MqttListenCommand.php`:
  - `handleMessage()` (lines 95–104): Does not reactivate inactive devices; updates `last_heartbeat_at` only if `$deviceModel && $deviceModel->is_active`.
  - `handleVerifyPush()` (lines 244–257): Drops telemetry immediately if device is missing or inactive. Un-enrolled devices are staged with `'is_active' => false`. No `AccessLog` created, no punch job dispatched, no `PushAck` returned to rogue senders.
  - `handleStrangerSnapPush()` (lines 344–357): Drops stranger telemetry if device is missing or inactive. No `StrangerSnap` created.
  - `handleDeviceAlert()` (lines 431–444): Drops safety alarms (`ClothHelmetSnapPush`, `FireSmokeSnapPush`, `BehaviorSnapPush`, `PlateSnapPush`) if device is missing or inactive.
  - `handleHeartbeat()` (lines 627–640) & `handleOnlineStatus()` (lines 651–684): Unknown devices staged with `is_active = false`. `DeviceStatusUpdated` is broadcast only if `$device->is_active`.
  - Malformed payload resilience: Tested invalid JSON, empty strings, arrays, missing `operator`/`info`, integer `facesluiceId`, 60+ character `facesluiceId`, and SQL injection strings (`DEV' OR 1=1 --`). All handled gracefully without uncaught exceptions or daemon crashes.

### 1.3 SEC-15: Biometric File Upload MIME Types & Stored XSS Prevention Observations
- **File:** `app/Http/Controllers/PersonnelController.php`:
  - `store()` (line 68) & `update()` (line 127):
    `'photo' => 'nullable|file|mimes:jpeg,jpg,png,webp|max:10240'`
  - Tested empirical upload of direct SVG (`standard.svg`), uppercase extension (`UPPERCASE.SVG`), disguised SVG (`disguised.jpg`, `disguised.png` with real file content inspected by PHP `finfo`), HTML (`exploit.html`), XML (`payload.xml`), and PHP scripts (`webshell.php`). All returned HTTP 422 Unprocessable Entity with validation errors on `photo`.
- **File:** `app/Services/ImageStorageService.php`:
  - `getMedia()` (lines 323–340): Explicitly blocks vector and active web extensions (`svg`, `xml`, `html`, `htm`), and checks MIME type (`str_contains($mimeType, 'svg') || str_contains($mimeType, 'xml') || str_contains($mimeType, 'html')`), returning `null` (HTTP 404).
  - Valid raster images (`clean.jpg`, `clean.png`, `clean.webp`) are served with `X-Content-Type-Options: nosniff`.

### 1.4 SEC-16: Symmetrical SSRF Protection on `photo_path` and `photo_url` Observations
- **File:** `app/Http/Controllers/PersonnelController.php` (lines 82–93 and 144–155):
  - Validates `isSafeUrl()` across both `photo_url` and `photo_path`:
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
  - Probed extensive SSRF target formats:
    - Cloud metadata: `http://169.254.169.254/latest/meta-data/`, `http://169.254.169.254/computeMetadata/v1/`
    - Loopback: `http://127.0.0.1:8000/`, `http://localhost/`, `http://0.0.0.0:80/`, `http://127.127.127.127/`
    - DNS rebinding: `http://127.0.0.1.nip.io/`
    - Alternative IP encodings: Octal (`0177.0.0.1`), Decimal (`2130706433`), Hex (`0x7f000001`)
    - RFC 1918 Private Ranges: `10.0.0.1`, `172.16.0.1`, `192.168.1.1`
    - Non-HTTP schemes: `file:///etc/passwd`, `gopher://`, `ftp://`
    - Userinfo tricks: `http://user:pass@127.0.0.1/`, `http://127.0.0.1#@example.com/`
    - Dual-input asymmetric probes: Safe `photo_url` + SSRF `photo_path`, and SSRF `photo_url` + safe `photo_path`.
  - All SSRF attempts failed validation and returned HTTP 422 Unprocessable Entity.
  - Legitimate relative storage paths (e.g. `strangers/2026/10/snapshot_987.jpg`) and public internet URLs (`https://example.com/avatar_test.jpg`) succeeded with HTTP 201 Created.

### 1.5 SEC-19: Reverse Proxy Trust Headers & Webhook IP Authentication Observations
- **File:** `app/Http/Controllers/HttpWebhookController.php` (line 61):
  ```php
  if ($clientIp === $device->ip_address || (app()->environment('local', 'testing') && in_array($clientIp, ['127.0.0.1', '::1'], true)))
  ```
  Verified: Loopback bypass check is strictly restricted to `app()->environment('local', 'testing')`.
- **File:** `bootstrap/app.php`:
  `$middleware->trustProxies(at: '*');` is registered.
- Adversarial probes executed:
  - In simulated `production` environment:
    - Client connecting with `REMOTE_ADDR: 127.0.0.1` received HTTP 401 Unauthorized.
    - Reverse proxy forwarding untrusted external IP (`X-Forwarded-For: 198.51.100.55`) received HTTP 401 Unauthorized.
    - Reverse proxy forwarding registered camera IP (`X-Forwarded-For: 192.168.1.200`) authenticated successfully (HTTP 200).
    - Inactive camera with matching IP received HTTP 401/403 (rejected).
    - Unregistered camera sending heartbeat in production received HTTP 401 (`Camera device not pre-registered`).
    - Configured webhook secret (`X-Webhook-Secret`) authenticates correctly independent of client IP, and rejects invalid secrets with HTTP 401.

---

## 2. Logic Chain

1. *From 1.2:* Prior to remediation, any WAN attacker could publish to `mqtt/face/<id>` and have an active camera created automatically. By setting `ENABLE_INSECURE_MQTT_TUNNEL=false` by default and having `MqttListenCommand` drop telemetry from un-enrolled or inactive devices (staging new devices strictly with `is_active = false`), rogue WAN telemetry injection is blocked.
2. *From 1.3:* Laravel's default `image` rule allowed SVGs, creating Stored XSS risks when files were served via `/api/media/{path}`. By replacing the validator with `mimes:jpeg,jpg,png,webp`, PHP's MIME inspection detects vector/SVG content even when disguised under `.jpg` or `.png` extensions. Furthermore, `ImageStorageService::getMedia()` blocks SVG/XML/HTML files and forces `X-Content-Type-Options: nosniff`.
3. *From 1.4:* Attackers previously bypassed SSRF checks by passing internal URLs in `photo_path` instead of `photo_url`. Validating both fields symmetrically with `isSafeUrl()` stops cloud metadata and private network SSRF attacks regardless of encoding, while preserving valid relative file paths.
4. *From 1.5:* In production behind reverse proxies, requests from the internet appeared with `REMOTE_ADDR = 127.0.0.1`. Gating the loopback check behind `app()->environment('local', 'testing')` and configuring trusted proxy resolution ensures that real client IPs forwarded in `X-Forwarded-For` are checked against enrolled camera IPs, eliminating the authentication bypass.
5. *From 1.1:* All 46 unit, feature, and adversarial stress tests pass with 257 assertions and zero failures. The frontend bundle compiles cleanly in 756ms.

---

## 3. Caveats

- **Reverse Proxy Subnet Narrowing in Production:** `trustProxies(at: '*')` trusts all reverse proxies. In production clusters with fixed ingress subnets (e.g. AWS VPC, Kubernetes Ingress CIDR, Cloudflare IP lists), administrators can optionally constrain `at:` to specific trusted CIDRs.
- **Milestone 3 Work In Progress:** Full test suite execution surfaced two test failures in `Tier1FeatureCoverageTest` and `Tier3CrossFeatureTest` relating to Feature 1 (Access Control Groups), which is under active development in Milestone 3. All Milestone 2 tests pass 100%.
- No other caveats.

---

## 4. Conclusion

**Verdict: APPROVE**

Milestone 2 remediations (SEC-13, SEC-15, SEC-16, SEC-19) are robust, withstand adversarial probing across all edge cases, and introduce no regressions.

---

## 5. Verification Method

To independently verify these findings:

1. **Run Adversarial Stress Test Suite:**
   ```bash
   php artisan test --filter=Milestone2AdversarialStressTest
   ```
   Asserts 14 tests pass, 137 assertions.

2. **Run Security Remediation Suite:**
   ```bash
   php artisan test --filter=SecurityRemediationTest
   ```
   Asserts 29 tests pass, 112 assertions.

3. **Run Telemetry Deduplication Suite:**
   ```bash
   php artisan test --filter=TelemetryDeduplicationTest
   ```
   Asserts 3 tests pass, 8 assertions.

4. **Verify Frontend Build:**
   ```bash
   npm run build
   ```
   Asserts Vite compiles cleanly with exit code 0.
