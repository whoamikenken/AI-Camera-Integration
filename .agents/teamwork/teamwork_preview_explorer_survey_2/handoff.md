# Security Investigation Handoff Report: Edge Ingestion & Input Security (SEC-13, SEC-15, SEC-16, SEC-19)

**Author:** Explorer Survey 2  
**Date:** 2026-10-07T01:58:00Z  
**Target Milestone:** Security Survey 2 / Milestone 2 (Edge Ingestion & Input Security)  
**Assigned Scope:** SEC-13, SEC-15, SEC-16, SEC-19  

---

## 1. Observation

### 1.1 SEC-13: Insecure WAN MQTT Tunnel & Rogue Device Ingestion
- **File:** `start-dev.sh` (lines 84–93):
  ```bash
  # C. Public bore TCP Tunnel for MQTT (enabled by default unless ENABLE_INSECURE_MQTT_TUNNEL=false)
  if [ "${ENABLE_INSECURE_MQTT_TUNNEL:-true}" != "false" ]; then
      echo -e "${RED}⚠ WARNING: Exposing unencrypted MQTT to public bore.pub tunnel...${NC}"
      ./bore local 1883 --to bore.pub --port 35803 > /tmp/bore.log 2>&1 &
      BORE_PID=$!
      sleep 1.5
      BORE_PORT=$(grep -oP 'listening at bore.pub:\K[0-9]+' /tmp/bore.log || echo "35803")
      echo -e "${YELLOW} Public MQTT Host: bore.pub:${BORE_PORT}${NC}"
  fi
  ```
  *Observed:* `${ENABLE_INSECURE_MQTT_TUNNEL:-true}` defaults to `"true"` if the variable is unset.
- **File:** `.env` (line 82):
  ```env
  ENABLE_INSECURE_MQTT_TUNNEL=true
  ```
- **File:** `.env.example` (lines 48–56):
  ```env
  # MQTT Broker (EMQX / Mosquitto)
  MQTT_HOST=mqtt
  MQTT_PORT=1883
  MQTT_TIMEOUT=10
  MQTT_KEEP_ALIVE=60
  MQTT_AUTH=false
  MQTT_USERNAME=
  MQTT_PASSWORD=
  MQTT_BASE_TOPIC=mqtt/face
  ```
  *Observed:* `ENABLE_INSECURE_MQTT_TUNNEL` is completely missing from `.env.example`, and `MQTT_AUTH=false` is default.
- **File:** `app/Console/Commands/MqttListenCommand.php`:
  - Lines 95–106 (`handleMessage`):
    ```php
    $throttleKey = "device_hb_throttle:{$deviceId}";
    if (!Cache::has($throttleKey)) {
        $deviceModel = Device::where('device_id', $deviceId)->first();
        if ($deviceModel) {
            $deviceModel->update([
                'last_heartbeat_at' => now(),
                'is_active' => true,
            ]);
        }
        Cache::put($throttleKey, true, 60);
    }
    ```
    *Observed:* Line 101 unconditionally updates `'is_active' => true` whenever any packet arrives from an existing device, effectively overriding administrative deactivation of suspicious devices.
  - Lines 243–246 (`handleVerifyPush`):
    ```php
    $device = Device::firstOrCreate(
        ['device_id' => $deviceId],
        ['name' => "Camera {$deviceId}", 'ip_address' => '192.168.1.100', 'is_active' => true]
    );
    ```
  - Lines 333–336 (`handleStrangerSnapPush`):
    ```php
    $device = Device::firstOrCreate(
        ['device_id' => $deviceId],
        ['name' => "Camera {$deviceId}", 'ip_address' => '192.168.1.100', 'is_active' => true]
    );
    ```
  - Lines 410–413 (`handleDeviceAlert`):
    ```php
    $device = Device::firstOrCreate(
        ['device_id' => $deviceId],
        ['name' => "Camera {$deviceId}", 'ip_address' => '192.168.1.100', 'is_active' => true]
    );
    ```
  - Lines 594–601 (`handleHeartbeat`):
    ```php
    $device = Device::firstOrCreate(
        ['device_id' => $deviceId],
        [
            'name' => $info['facesname'] ?? $info['Name'] ?? "Camera {$deviceId}",
            'ip_address' => $info['ip'] ?? '192.168.1.100',
            'is_active' => true,
        ]
    );
    ```
  - Lines 615–622 (`handleOnlineStatus`):
    ```php
    $device = Device::firstOrCreate(
        ['device_id' => $deviceId],
        [
            'name' => $info['facesname'] ?? $info['Name'] ?? "Camera {$deviceId}",
            'ip_address' => $info['ip'] ?? '192.168.1.100',
            'is_active' => true,
        ]
    );
    ```
  *Observed:* Any unrecognized device identifier arriving over an unauthenticated MQTT broker is immediately inserted into the database as an active device (`is_active = true`), and its telemetry (`AccessLog`, `StrangerSnap`, `DeviceAlert`, `ProcessAttendancePunchJob`) is processed and broadcast to WebSockets.
- **Contrast File:** `app/Http/Controllers/HttpWebhookController.php`:
  - Lines 191–197 (`handleVerify`):
    ```php
    if (!$device || !$device->is_active) {
        return response()->json([
            'code' => 403,
            'desc' => 'Forbidden: Camera device not enrolled or inactive',
        ], 403);
    }
    ```
  - Lines 130–140 (`handleHeartbeat`):
    ```php
    if (!$device) {
        // Untrusted payload: Stage in unapproved status (is_active = false)
        $device = Device::create([
            'device_id' => $deviceId,
            'name' => $info['facesname'] ?? $info['Name'] ?? "Camera {$deviceId}",
            'ip_address' => $request->ip() ?: '127.0.0.1',
            'is_active' => false,
            'last_heartbeat_at' => now(),
        ]);
        Cache::put($throttleKey, true, 60);
        event(new DeviceStatusUpdated($device));
    }
    ```

---

### 1.2 SEC-15: Biometric File Upload MIME Types & SVG Stored XSS
- **File:** `app/Http/Controllers/PersonnelController.php`:
  - Line 68 in `store()`:
    ```php
    'photo' => 'nullable|image|max:10240', // 10MB max upload
    ```
  - Line 126 in `update()`:
    ```php
    'photo' => 'nullable|image|max:10240',
    ```
  *Observed:* In Laravel's validation rule set, the `image` rule allows the following MIME types: `image/jpeg`, `image/png`, `image/gif`, `image/bmp`, `image/svg+xml`, and `image/webp`. Thus, `.svg` files are permitted.
- **File:** `routes/api.php` (lines 283–293):
  ```php
  Route::get('media/{path}', function (\Illuminate\Http\Request $request, string $path, \App\Services\ImageStorageService $storage) {
      $media = $storage->getMedia($path);
      if (!$media) {
          abort(404, 'Media not found.');
      }
      return response($media['content'], 200, [
          'Content-Type' => $media['mime_type'],
          'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
          'X-Content-Type-Options' => 'nosniff',
      ]);
  })->where('path', '.*')->middleware('permission:personnel.view,devices.view,attendance.view,visitors.view');
  ```
  *Observed:* When a requested file has MIME type `image/svg+xml`, it is returned directly inline to the browser with `Content-Type: image/svg+xml`. If an SVG containing inline `<script>` tags or event handlers (`onload`, `onerror`) is retrieved, it executes scripts in the browser origin.
- **File:** `app/Services/ImageStorageService.php` (lines 316–327 in `getMedia`):
  ```php
  if (Storage::disk($disk)->exists($cleanPath)) {
      $mimeType = Storage::disk($disk)->mimeType($cleanPath) ?: 'image/jpeg';
      return [
          'content' => Storage::disk($disk)->get($cleanPath),
          'mime_type' => $mimeType,
      ];
  }
  ```
  *Observed:* `getMedia()` does not restrict file extensions or MIME types to raster formats, nor does it force attachment download headers for SVGs.

---

### 1.3 SEC-16: Consistent SSRF Protection on `photo_path` in `PersonnelController`
- **File:** `app/Http/Controllers/PersonnelController.php`:
  - Lines 84–98 in `store()`:
    ```php
    } elseif (!empty($validated['photo_url']) || !empty($validated['photo_path'])) {
        $source = $validated['photo_url'] ?? $validated['photo_path'];
        if (!empty($validated['photo_url']) && filter_var($source, FILTER_VALIDATE_URL) && !$this->storageService->isSafeUrl($source)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'photo_url' => ['The provided photo URL points to a restricted or private network address.'],
            ]);
        }
        $stored = $this->storageService->storeFromUrlOrPath($source, 'personnel');
        if ($stored) {
            $validated['photo_path'] = $stored['path'];
            $validated['photo_base64'] = $stored['base64'];
        } else {
            $validated['photo_path'] = str_replace('/storage/', '', parse_url($source, PHP_URL_PATH) ?? $source);
        }
    }
    ```
  - Lines 142–156 in `update()`:
    ```php
    } elseif ((!empty($validated['photo_url']) || !empty($validated['photo_path'])) && ($validated['photo_url'] ?? $validated['photo_path']) !== $personnel->photo_path) {
        $source = $validated['photo_url'] ?? $validated['photo_path'];
        if (!empty($validated['photo_url']) && filter_var($source, FILTER_VALIDATE_URL) && !$this->storageService->isSafeUrl($source)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'photo_url' => ['The provided photo URL points to a restricted or private network address.'],
            ]);
        }
        $stored = $this->storageService->storeFromUrlOrPath($source, 'personnel');
        if ($stored) {
            $validated['photo_path'] = $stored['path'];
            $validated['photo_base64'] = $stored['base64'];
        } else {
            $validated['photo_path'] = str_replace('/storage/', '', parse_url($source, PHP_URL_PATH) ?? $source);
        }
    }
    ```
  *Observed:* Both `store()` and `update()` gate the `isSafeUrl()` anti-SSRF validation behind `!empty($validated['photo_url'])`.
  If a caller submits an external or private URL in `photo_path` (e.g. `{"photo_path": "http://169.254.169.254/latest/meta-data/"}`) while omitting `photo_url`, the `ValidationException` is completely bypassed.
  `storeFromUrlOrPath` internally detects the unsafe URL and returns `null`, but the `else` branch executes:
  `$validated['photo_path'] = str_replace('/storage/', '', parse_url($source, PHP_URL_PATH) ?? $source);`, saving the target path into the database without rejection.

---

### 1.4 SEC-19: Reverse-Proxy Loopback IP Authentication Bypass in Webhooks
- **File:** `app/Http/Controllers/HttpWebhookController.php` (lines 58–66):
  ```php
  // 4. Pre-enrolled camera edge IP allowlist (or loopback in local/testing)
  if ($device && $device->is_active) {
      $clientIp = $request->ip();
      if ($clientIp === $device->ip_address || in_array($clientIp, ['127.0.0.1', '::1'], true)) {
          return true;
      }
  }

  return false;
  ```
  *Observed:* Line 61 checks `in_array($clientIp, ['127.0.0.1', '::1'], true)` unconditionally in all environments. The code does NOT verify `app()->environment('local', 'testing')`, despite the comment explicitly stating "(or loopback in local/testing)".
- **File:** `bootstrap/app.php` (lines 17–38):
  *Observed:* The `$middleware` callback configures CSRF exclusions, aliases, redirectGuestsTo, query token prepending, and security headers, but `$middleware->trustProxies(...)` is never called.
  When deployed behind a reverse proxy (such as NGINX, HAProxy, AWS ALB, Cloudflare Tunnel), `$request->ip()` resolves to `REMOTE_ADDR` (which is `127.0.0.1` for local proxies), allowing public internet requests to claim `127.0.0.1` and bypass webhook authentication.

---

### 1.5 Automated Test Suite Execution Baseline
- **Command:** `php artisan test`
- **Result:**
  ```json
  {"tool":"phpunit","result":"passed","tests":360,"passed":358,"assertions":1472,"duration_ms":63221,"skipped":2}
  ```
  All 358 existing feature and unit tests pass with zero errors and zero failures.
  However, there are coverage gaps for the specific security assertions required by SEC-13, SEC-15, SEC-16, and SEC-19.

---

## 2. Logic Chain

### 2.1 SEC-13 Logic Chain
1. *From 1.1:* `start-dev.sh` defaults `ENABLE_INSECURE_MQTT_TUNNEL` to `true`, and `.env` has `ENABLE_INSECURE_MQTT_TUNNEL=true`.
2. *From 1.1:* When developer environments launch, `bore` creates a public TCP tunnel exposing local port 1883 over `bore.pub:35803`.
3. *From 1.1:* `.env.example` configures `MQTT_AUTH=false`, allowing anonymous MQTT clients to connect without credentials.
4. *From 1.1:* `MqttListenCommand` subscribes to `mqtt/face/#` and invokes `Device::firstOrCreate(['device_id' => $deviceId], ['is_active' => true])` for any incoming message (`handleVerifyPush`, `handleStrangerSnapPush`, `handleDeviceAlert`, `handleHeartbeat`, `handleOnlineStatus`).
5. *From 1.1:* Furthermore, `handleMessage` automatically resets `'is_active' => true` on any existing device when a packet arrives, negating manual administrative disabling of compromised cameras.
6. *Inference:* An anonymous attacker anywhere on the internet can publish synthetic payloads (e.g. `VerifyPush`, `StrSnapPush`, `BehaviorSnapPush`) to `mqtt/face/<random_id>`, automatically creating active devices in PostgreSQL, injecting fake attendance punch logs, creating emergency alerts, and spamming WebSocket feeds.
7. *Remediation Strategy:*
   - Set `ENABLE_INSECURE_MQTT_TUNNEL=false` by default in `start-dev.sh`, `.env.example`, and `.env`.
   - In `MqttListenCommand`, verify device enrollment against `devices` table:
     - For telemetry events (`VerifyPush`, `StrSnapPush`, `DeviceAlert`): If `$device` is not found or `$device->is_active === false`, reject/drop the message immediately. If un-enrolled, do NOT create an active device (optionally stage in unapproved state `is_active = false` like `HttpWebhookController::handleHeartbeat`, but never create an active device).
     - For heartbeats/online: If un-enrolled, stage with `is_active = false` and do NOT broadcast online status until approved.
     - In `handleMessage`, do NOT overwrite `is_active => true` on heartbeat updates.

### 2.2 SEC-15 Logic Chain
1. *From 1.2:* `PersonnelController::store` and `update` use Laravel's `image` validator rule.
2. *From 1.2:* Laravel's `image` rule includes `image/svg+xml`.
3. *From 1.2:* SVG is an XML-based vector format capable of carrying active content (`<script>` elements, event listeners like `onload`, `<foreignObject>`).
4. *From 1.2:* Edge camera hardware (such as X40Y series face terminals) only supports raster images (`JPEG`, `PNG`, `WebP`) for neural network face embeddings. SVG files cannot be parsed by camera face engines.
5. *From 1.2:* When images are served via `GET /api/media/{path}`, `routes/api.php` sets `Content-Type: image/svg+xml` without `Content-Disposition: attachment` or a restrictive sandbox header.
6. *Inference:* An authenticated user with personnel creation/editing permissions can upload an SVG containing JavaScript. Anyone viewing or opening this file directly in the browser will execute the script in the context of the camera hub application origin (Stored XSS).
7. *Remediation Strategy:*
   - In `PersonnelController::store` and `update`, replace `'photo' => 'nullable|image|max:10240'` with strict raster file validation:
     `'photo' => 'nullable|file|mimes:jpeg,jpg,png,webp|max:10240'`.
   - In `ImageStorageService::getMedia()`, reject SVG files (`.svg` extension or `image/svg+xml` MIME type) by returning `null`, or strictly serve only permitted raster MIME types (`image/jpeg`, `image/png`, `image/webp`).

### 2.3 SEC-16 Logic Chain
1. *From 1.3:* `PersonnelController::store` and `update` validate anti-SSRF address safety using:
   `if (!empty($validated['photo_url']) && filter_var($source, FILTER_VALIDATE_URL) && !$this->storageService->isSafeUrl($source))`
2. *From 1.3:* If an attacker passes an internal/private address in `photo_path` (e.g. `http://169.254.169.254/latest/meta-data/` or `http://127.0.0.1:8000/`) and leaves `photo_url` empty or null, `!empty($validated['photo_url'])` evaluates to `false`.
3. *From 1.3:* The `ValidationException` is skipped.
4. *From 1.3:* `storeFromUrlOrPath` detects the unsafe URL and returns `null`.
5. *From 1.3:* In the fallback `else` branch, `$validated['photo_path']` is populated with `parse_url($source, PHP_URL_PATH)` and saved into the database with a 201 Created or 200 OK response.
6. *Inference:* The validation gate is asymmetric and permits SSRF target URLs in `photo_path`. If downstream sync jobs, camera dispatchers, or export routines attempt to resolve this path as a URL, SSRF attacks can occur.
7. *Remediation Strategy:*
   - Apply `isSafeUrl()` validation symmetrically to both `photo_url` and `photo_path`.
   - Iterate over `['photo_url', 'photo_path']`: if either field is present and is a valid URL (`filter_var($val, FILTER_VALIDATE_URL)`), verify `isSafeUrl($val)`. If unsafe, throw `ValidationException::withMessages([$field => ['The provided photo URL points to a restricted or private network address.']])`.
   - Preserve valid relative storage paths (e.g. `strangers/test_stranger.jpg` or `personnel/2026/10/abc.jpg`), where `filter_var($val, FILTER_VALIDATE_URL)` evaluates to `false`.

### 2.4 SEC-19 Logic Chain
1. *From 1.4:* `HttpWebhookController::authenticateWebhook` contains:
   `if ($clientIp === $device->ip_address || in_array($clientIp, ['127.0.0.1', '::1'], true))`
2. *From 1.4:* This check allows loopback requests unconditionally, without testing `app()->environment('local', 'testing')`.
3. *From 1.4:* `bootstrap/app.php` lacks `$middleware->trustProxies(...)`.
4. *From 1.4:* When the application is deployed behind a reverse proxy (e.g. Cloudflare Tunnel, NGINX on same host, Kubernetes ingress, HAProxy), the reverse proxy communicates with PHP on `127.0.0.1`.
5. *From 1.4:* Because trusted proxies are not configured, Symfony does not trust `X-Forwarded-For` and reports `$request->ip()` as `127.0.0.1` for all external traffic.
6. *Inference:* Any external attacker on the internet sending requests through the reverse proxy to `/api/Subscribe/Verify`, `/api/Subscribe/Snap`, or `/api/Subscribe/heartbeat` for any active enrolled device will have their request treated as coming from `127.0.0.1`. The loopback bypass immediately authenticates them with zero credentials.
7. *Remediation Strategy:*
   - In `HttpWebhookController::authenticateWebhook`:
     Restrict loopback IP acceptance to:
     `if ($clientIp === $device->ip_address || (app()->environment('local', 'testing') && in_array($clientIp, ['127.0.0.1', '::1'], true)))`
   - In `bootstrap/app.php`:
     Configure trusted proxies in middleware setup:
     `$middleware->trustProxies(at: '*');`
     allowing real client IPs forwarded by reverse proxies to be resolved correctly.

---

## 3. Caveats

1. **Local Test Environment Behavior for Webhooks:**
   - In PHPUnit feature tests (`tests/Feature/`), Laravel defaults `$request->ip()` to `127.0.0.1`.
   - Restricting the loopback check to `app()->environment('local', 'testing')` ensures that all existing tests in `HttpProtocolV113Test` and `SecurityRemediationTest` will continue to pass without modification.
   - However, a dedicated test verifying the rejection of loopback IPs in simulated production (`app()->detectEnvironment(fn() => 'production')`) must be added to guarantee regression coverage.

2. **MQTT Unit Test Pre-Enrollment Dependency:**
   - In `tests/Feature/TelemetryDeduplicationTest.php` (lines 48 and 86), the mock commands `testHandleStrangerSnap` and `testHandleVerify` previously relied on `Device::firstOrCreate` inside `MqttListenCommand` to auto-enroll device `'1026230'`.
   - Once `MqttListenCommand` drops telemetry for un-enrolled devices, `TelemetryDeduplicationTest` must ensure `Device::create(['device_id' => '1026230', 'name' => 'Test Cam', 'ip_address' => '127.0.0.1', 'is_active' => true])` is created prior to dispatching MQTT test events, or the deduplication test will drop the packets before recording them.

3. **Relative File Paths in `photo_path`:**
   - Some features (such as enrolling personnel from stranger snapshots in `PersonnelSyncTest.php::test_can_enroll_personnel_from_stranger_snap_url`) pass local relative paths like `'strangers/test_stranger.jpg'` in `photo_path`.
   - The anti-SSRF check must only execute when `filter_var($source, FILTER_VALIDATE_URL)` returns `true`. String paths like `'strangers/xyz.jpg'` must NOT be rejected by the URL filter.

4. **Biometric Face Photo Uploads via Base64:**
   - Base64 image uploads in `PersonnelController` pass through `ImageStorageService::storeFromBase64()`:
     ```php
     $extension = str_contains($mimeType, 'png') ? 'png' : 'jpg';
     ```
     This method already normalizes to raster formats (`png` or `jpg`), so Base64 uploads cannot store SVG files. The vulnerability was specifically in file uploads via multipart form data (`$request->file('photo')`).

---

## 4. Conclusion & Concrete Implementation Plan

### 4.1 SEC-13: Insecure WAN MQTT Tunnel & Rogue Device Ingestion
- **Target Files:**
  - `start-dev.sh`
  - `.env.example`
  - `.env`
  - `app/Console/Commands/MqttListenCommand.php`
  - `tests/Feature/TelemetryDeduplicationTest.php`
- **Exact Changes:**
  1. `start-dev.sh` (line 85):
     ```bash
     # Before:
     if [ "${ENABLE_INSECURE_MQTT_TUNNEL:-true}" != "false" ]; then

     # After:
     if [ "${ENABLE_INSECURE_MQTT_TUNNEL:-false}" = "true" ]; then
     ```
  2. `.env.example`:
     Add line:
     ```env
     ENABLE_INSECURE_MQTT_TUNNEL=false
     ```
  3. `.env`:
     Update line 82:
     ```env
     ENABLE_INSECURE_MQTT_TUNNEL=false
     ```
  4. `app/Console/Commands/MqttListenCommand.php`:
     - In `handleMessage` (lines 97–104):
       Do not update `'is_active' => true`. Only update `last_heartbeat_at => now()` if `$deviceModel->is_active`.
     - In `handleVerifyPush` (lines 243–247):
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
     - In `handleStrangerSnapPush` (lines 333–337) and `handleDeviceAlert` (lines 410–414):
       Apply the same device verification logic — reject/drop if `$device` is missing or inactive.
     - In `handleHeartbeat` (lines 594–605) and `handleOnlineStatus` (lines 615–623):
       If the device does not exist, create it with `'is_active' => false`. Only broadcast status updates if `$device->is_active`.

---

### 4.2 SEC-15: Biometric File Upload MIME Types & Disallowing SVG
- **Target Files:**
  - `app/Http/Controllers/PersonnelController.php`
  - `app/Services/ImageStorageService.php`
  - `routes/api.php`
- **Exact Changes:**
  1. `app/Http/Controllers/PersonnelController.php`:
     - Line 68 (in `store`):
       ```php
       // Before:
       'photo' => 'nullable|image|max:10240',

       // After:
       'photo' => 'nullable|file|mimes:jpeg,jpg,png,webp|max:10240',
       ```
     - Line 126 (in `update`):
       ```php
       // Before:
       'photo' => 'nullable|image|max:10240',

       // After:
       'photo' => 'nullable|file|mimes:jpeg,jpg,png,webp|max:10240',
       ```
  2. `app/Services/ImageStorageService.php`:
     - In `getMedia(string $path)` (around line 318):
       ```php
       if (Storage::disk($disk)->exists($cleanPath)) {
           $ext = strtolower(pathinfo($cleanPath, PATHINFO_EXTENSION));
           if (in_array($ext, ['svg', 'xml', 'html', 'htm'], true)) {
               return null;
           }

           $mimeType = Storage::disk($disk)->mimeType($cleanPath) ?: 'image/jpeg';
           if (str_contains(strtolower($mimeType), 'svg') || str_contains(strtolower($mimeType), 'xml')) {
               return null;
           }

           return [
               'content' => Storage::disk($disk)->get($cleanPath),
               'mime_type' => $mimeType,
           ];
       }
       ```

---

### 4.3 SEC-16: Consistent SSRF Protection on `photo_path` in `PersonnelController`
- **Target Files:**
  - `app/Http/Controllers/PersonnelController.php`
- **Exact Changes:**
  - In `store()` (lines 84–98):
    ```php
    // Before:
    } elseif (!empty($validated['photo_url']) || !empty($validated['photo_path'])) {
        $source = $validated['photo_url'] ?? $validated['photo_path'];
        if (!empty($validated['photo_url']) && filter_var($source, FILTER_VALIDATE_URL) && !$this->storageService->isSafeUrl($source)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'photo_url' => ['The provided photo URL points to a restricted or private network address.'],
            ]);
        }
        ...

    // After:
    } elseif (!empty($validated['photo_url']) || !empty($validated['photo_path'])) {
        foreach (['photo_url', 'photo_path'] as $field) {
            if (!empty($validated[$field]) && filter_var($validated[$field], FILTER_VALIDATE_URL)) {
                if (!$this->storageService->isSafeUrl($validated[$field])) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        $field => ['The provided photo URL points to a restricted or private network address.'],
                    ]);
                }
            }
        }
        $source = $validated['photo_url'] ?? $validated['photo_path'];
        $stored = $this->storageService->storeFromUrlOrPath($source, 'personnel');
        if ($stored) {
            $validated['photo_path'] = $stored['path'];
            $validated['photo_base64'] = $stored['base64'];
        } else {
            $validated['photo_path'] = str_replace('/storage/', '', parse_url($source, PHP_URL_PATH) ?? $source);
        }
    }
    ```
  - In `update()` (lines 142–156):
    Apply the identical loop over `['photo_url', 'photo_path']` ensuring any field containing a URL is checked with `isSafeUrl()`.

---

### 4.4 SEC-19: Prevent Reverse-Proxy Loopback IP Authentication Bypass
- **Target Files:**
  - `app/Http/Controllers/HttpWebhookController.php`
  - `bootstrap/app.php`
- **Exact Changes:**
  1. `app/Http/Controllers/HttpWebhookController.php` (line 61):
     ```php
     // Before:
     if ($clientIp === $device->ip_address || in_array($clientIp, ['127.0.0.1', '::1'], true)) {
         return true;
     }

     // After:
     if ($clientIp === $device->ip_address || (app()->environment('local', 'testing') && in_array($clientIp, ['127.0.0.1', '::1'], true))) {
         return true;
     }
     ```
  2. `bootstrap/app.php` (inside `$middleware` callback):
     ```php
     $middleware->trustProxies(at: '*');
     ```

---

## 5. Verification Method

To independently verify these findings and the proposed remediations:

1. **Automated Test Suite Verification:**
   Execute full test suite:
   ```bash
   php artisan test
   ```
   Ensure all 360 tests pass without regressions.

2. **SEC-13 Verification:**
   - Inspect `start-dev.sh` and verify:
     `ENABLE_INSECURE_MQTT_TUNNEL=false ./start-dev.sh` (or running without variable) does NOT launch `bore`.
   - In a test or artisan command:
     Publish an MQTT `VerifyPush` message with `facesluiceId: 'ROGUE-DEVICE-777'`.
     Assert that `Device::where('device_id', 'ROGUE-DEVICE-777')->first()->is_active` is NOT `true`.
     Assert that `AccessLog::where('device_id', 'ROGUE-DEVICE-777')->count()` is `0`.

3. **SEC-15 Verification:**
   - In a feature test:
     Create an `UploadedFile::fake()->create('malicious.svg', 100, 'image/svg+xml')`.
     POST to `/api/personnel` with `'photo' => $file`.
     Assert HTTP 422 Unprocessable Entity with validation error on `'photo'`.
   - Call `app(ImageStorageService::class)->getMedia('personnel/test.svg')`.
     Assert result is `null`.

4. **SEC-16 Verification:**
   - In a feature test:
     POST to `/api/personnel` with:
     ```json
     {
         "name": "SSRF Tester",
         "person_type": 0,
         "photo_path": "http://169.254.169.254/latest/meta-data/"
     }
     ```
     Assert HTTP 422 Unprocessable Entity with validation error on `'photo_path'`.
   - POST to `/api/personnel` with relative path:
     ```json
     {
         "name": "Valid Tester",
         "person_type": 0,
         "photo_path": "strangers/test.jpg"
     }
     ```
     Assert HTTP 201 Created (relative paths allowed).

5. **SEC-19 Verification:**
   - In a feature test simulating production:
     ```php
     app()->detectEnvironment(fn() => 'production');
     $device = Device::create([
         'device_id' => 'CAM-PROD-01',
         'name' => 'Prod Camera',
         'ip_address' => '192.168.1.200',
         'is_active' => true,
     ]);
     $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
         ->postJson('/api/Subscribe/Verify', [
             'DeviceID' => 'CAM-PROD-01',
             'info' => ['PersonID' => 1, 'VerifyStatus' => 1],
         ]);
     $response->assertStatus(401);
     ```
     Assert request is rejected with HTTP 401 Unauthorized because loopback bypass is disabled in production.

---
*Report completed and verified against codebase.*
