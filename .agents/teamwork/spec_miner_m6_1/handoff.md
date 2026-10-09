# HANDOFF — Milestone M6 Specification Mining Report

## Features Discovered
| # | Category | Feature | Description | Inputs | Outputs | Error Behavior | Discovered Via |
|---|----------|---------|-------------|--------|---------|----------------|----------------|
| 34 | API Uniformity | Standard API Response Envelope (`ApiResponse`) | Unified JSON response wrapper providing standard `{ success, message, data, meta }` with backward-compatible root-level attribute and pagination key propagation | `$data` (mixed), `$message` (string, default: `'Success'`), `$status` (int, default: 200), `$meta` (array, default: `[]`) | `JsonResponse` with `{ success: true, message, data, meta: { timestamp, version, ... } }` | `ApiResponse::error($message, $status, $errors, $meta)` returning `{ success: false, message, errors, meta }` | `Tier1FeatureCoverageTest.php:1658-1669`, `system-evo.md:274-281`, `orchestrator_11/PROJECT.md:50` |
| 35 | Hardware Boundary | Hardware Webhook Protocol Exemption | Strict protocol preservation for embedded edge camera HTTP push callbacks (`/Subscribe/*`), explicitly exempted from `ApiResponse` wrapping | JSON push payload with `operator` (`HeartBeat`, `VerifyPush`, `StrSnapPush`), `info` (`DeviceID`, `PersonID`, etc.), `SanpPic`, `ScenePic` | Raw hardware JSON: `{"code": 200, "desc": "OK", "info": {"Result": "Ok"}}` or duplicate `{"code": 200, "desc": "OK (Duplicate ignored)", "info": {"Result": "Ok"}}` | 400 `{"code": 400, "desc": "..."}`, 401 `{"code": 401, "desc": "..."}`, 403 `{"code": 403, "desc": "..."}` | `Tier1FeatureCoverageTest.php:1671-1687`, `HttpWebhookController.php:89-373`, `HttpProtocolV113Test.php:249-275` |
| 36 | API Validation | 24 Dedicated Form Request Classes | Decoupled validation requests extracting inline validation from domain controllers across Employee, Device, Personnel, Shift, Visitor, Leave, and AccessGroup | Form input arrays via HTTP POST/PUT/PATCH | Validated payload array via `$request->validated()` | 422 Unprocessable Entity with standard validation error structure `{ "message": "...", "errors": { field: [...] } }` | `Tier1FeatureCoverageTest.php:1689-1699`, `explorer_survey_3/handoff.md:75`, domain controllers |
| 37 | API Documentation | Automated OpenAPI Documentation (`dedoc/scramble`) | Dynamic OpenAPI 3.1 interactive API documentation generator served at `/docs/api` and spec at `/docs/api.json` with Sanctum Bearer token security definition | HTTP GET requests to `/docs/api` and `/docs/api.json` | Swagger UI / Stoplight Elements HTML (200 OK) and OpenAPI 3.1 JSON schema | 403 if `viewApiDocs` gate fails; resolved by defining gate in `AppServiceProvider` | `Tier1FeatureCoverageTest.php:1701-1708`, `system-evo.md:283`, `composer.json` |
| 38 | Frontend Composable | Universal Paginated Resource (`usePaginatedResource.js`) | Reactive composable encapsulating pagination state, debounced search (300ms), sorting, loading, error, and universal response parsing | `fetcher` (function or endpoint string), `options` (`{ initialFilters, debounceMs, perPage, defaultSort }`) | `{ items, pagination, loading, error, searchQuery, filters, page, perPage, fetchData, goToPage, setFilter, resetFilters }` | Captures error in `error.value`, resets `loading.value = false`, keeps existing items or sets empty | `Tier1FeatureCoverageTest.php:1710-1715`, `explorer_survey_3/handoff.md:79`, `system-evo.md:295` |
| 39 | Frontend Composable | Live Telemetry Stream (`useLiveTelemetryStream.js`) | Reverb/Echo channel subscriber managing camera verifications, stranger alerts, device status events, event buffering, deduplication, and Web Audio chime | `options` (`{ channels, maxBufferSize, enableAudio, onEvent }`) | `{ events, isConnected, latestEvent, clearEvents, playChime, subscribe, unsubscribe }` | Web Audio synthesizer fallback when audio context is blocked; automatic reconnect on WebSocket drop | `Tier1FeatureCoverageTest.php:1716-1721`, `explorer_survey_3/handoff.md:80`, `system-evo.md:296` |
| 40 | Frontend Composable | Biometric Webcam Capture (`useBiometricCapture.js`) | Reusable webcam media stream acquisition, canvas-based centered 1:1 square crop aspect ratio validation, and base64 JPEG export | `options` (`{ targetSize: 480, minDimensions: 200, facingMode: 'user' }`) | `{ isStreaming, capturedImage, error, hasCamera, startCamera, stopCamera, capturePhoto, clearPhoto }` | Traps `NotAllowedError` / `NotFoundError`, updates `error.value`, unmount cleanup stops all active media tracks | `Tier1FeatureCoverageTest.php:1722-1727`, `EmployeeFormModal.vue:455-489`, `system-evo.md:297` |
| 41 | Frontend Architecture | Frontend View Refactoring | Refactor frontend components/views to consume composables, specifically creating `components/telemetry/LiveTelemetry.vue` and updating management views | Vue 3 SFC templates and scripts | Cleaner SFCs with ~250 lines of duplicate pagination, webcam, and Echo boilerplate removed; Vite build <1.5s | Build passes cleanly with 0 syntax or bundling errors (`npm run build`) | `Tier1FeatureCoverageTest.php:1728-1732`, `orchestrator_11/PROJECT.md:57` |

---

## Edge Cases
| # | Feature | Input | Observed Behavior |
|---|---------|-------|-------------------|
| 1 | `ApiResponse::success` | Associative array with root keys matching existing test assertions (e.g. `['id' => 1, 'device_id' => 'CAM-01']`) | Envelope contains `success: true`, `data: [...]`, `meta: [...]`, while root keys must also remain accessible so `$response->assertJson(['id' => 1])` passes without breaking existing feature tests in `DeviceManagementTest.php:74-78`. |
| 2 | `ApiResponse::paginated` | Laravel `LengthAwarePaginator` instance | Envelope wraps records inside `data`, places metadata inside `meta.pagination`, and copies `current_page`, `last_page`, `per_page`, `total`, `from`, `to` to the root JSON object so both legacy paginator assertions and new envelope assertions pass simultaneously. |
| 3 | `HttpWebhookController` | POST `/Subscribe/heartbeat` with `{ operator: 'HeartBeat', info: { facesluiceId: 'CAM-01' } }` | Raw hardware protocol `{ code: 200, desc: 'OK', info: { Result: 'Ok' } }` is returned directly. Any middleware or global wrapper MUST exempt `/Subscribe/*` and `/api/Subscribe/*`. |
| 4 | `StoreEmployeeRequest` | Empty POST body `{}` to `/api/employees` | Returns HTTP 422 with validation errors containing `first_name` per `Tier1FeatureCoverageTest.php:1696-1699`. |
| 5 | `StoreDeviceRequest` | POST `/api/devices` with invalid `port` (e.g. `70000`) or missing `device_id` | Returns HTTP 422 with validation errors on `device_id` and `port`. |
| 6 | `Scramble /docs/api` | GET `/docs/api` in testing environment (`APP_ENV=testing`) | Returns HTTP 200 with OpenAPI documentation HTML; requires `Gate::define('viewApiDocs', fn ($user) => true)` to prevent HTTP 403 in testing/CI. |
| 7 | `usePaginatedResource` | Endpoint returning nested `{ data: { data: [...], current_page: 1 } }` vs flat `{ data: [...] }` vs bare array `[...]` | Universal parser detects shape: unrolls `res.data.data` or `res.data` into `items.value`, extracts `current_page`, `last_page`, `total` from `res.data.meta` or root `res.data`. |
| 8 | `useBiometricCapture` | Camera feed with rectangular aspect ratio (e.g. 16:9 1280x720 or 4:3 640x480) | Canvas calculates `minDim = Math.min(videoWidth, videoHeight)`, centers bounding box `(videoWidth - minDim) / 2`, and crops exactly 1:1 square before generating base64 JPEG data URL. |
| 9 | `useBiometricCapture` | Component unmount while webcam stream is active | `onUnmounted()` hook iterates `mediaStream.getTracks()` and executes `track.stop()`, immediately extinguishing the browser hardware indicator. |
| 10 | `useLiveTelemetryStream` | Rapid burst of 100 verification telemetry packets in under 1 second | Composable buffers events up to `maxBufferSize` (e.g. 50), discards duplicates by `id`/`captured_at`, and prevents UI frame thrashing. |

---

## 1. Observation
1. **Test Suite Baseline & Milestone 6 Status**:
   - `php artisan test`: 749 total tests ran in 30.5 seconds (`740 passed, 0 failures, 9 skipped`).
   - Milestone 6 E2E tests in `tests/Feature/E2E/Tier1FeatureCoverageTest.php` (lines 1658-1732):
     - `test_f34` (Standard API Response Envelope): Skipped (`requireClass('App\Http\Responses\ApiResponse')`).
     - `test_f35` (Hardware Webhook Protocol Exemption): **PASSED** (verifies `/Subscribe/heartbeat` returns `code: 200`, `desc: 'OK'`).
     - `test_f36` (Dedicated Form Requests): Skipped (`requireClass('App\Http\Requests\StoreEmployeeRequest')`).
     - `test_f37` (Automated OpenAPI Documentation): Skipped (`requireRoute('/docs/api', 'GET')`).
     - `test_f38` (Universal Paginated Resource Composable): Skipped (`requireFile('resources/js/composables/usePaginatedResource.js')`).
     - `test_f39` (Live Telemetry Stream Composable): Skipped (`requireFile('resources/js/composables/useLiveTelemetryStream.js')`).
     - `test_f40` (Biometric Capture Composable): Skipped (`requireFile('resources/js/composables/useBiometricCapture.js')`).
     - `test_f41` (Frontend Views Refactored): Skipped (`requireFile('resources/js/components/telemetry/LiveTelemetry.vue')`).
   - Legacy Milestone 6 tests (`test_m6_...` lines 879-929) covering notifications, daily/monthly reports, and payroll export are all **PASSED** (5 passed).
2. **Existing Controller & Request Landscape**:
   - Directory `app/Http/Requests/` currently does not exist (`list_dir` on `app/Http/` contains only `Controllers` and `Middleware`).
   - Over 50 inline `$request->validate([...])` calls exist across controllers (`EmployeeController`, `DeviceController`, `PersonnelController`, `ShiftController`, `VisitorController`, `LeaveController`, `AccessGroupController`, `RegularizationController`, etc.).
   - `DeviceManagementTest.php:74-78` asserts top-level keys directly:
     ```php
     $response->assertStatus(200)->assertJson([
         'id' => $device->id,
         'device_id' => 'CAM-DETAIL-01',
         'name' => 'Front Gate Camera',
     ]);
     ```
   - Paginator endpoints in `EmployeeController:71`, `AccessLogController:51`, and `StrangerSnapController:29` currently return raw Laravel paginator objects directly (`return response()->json($employees);`).
3. **Hardware Webhook Invariant**:
   - `HttpWebhookController.php:150-154, 265-269, 368-372` returns raw camera protocol responses:
     ```php
     return response()->json([
         'code' => 200,
         'desc' => 'OK',
         'info' => ['Result' => 'Ok'],
     ]);
     ```
   - `Tier1FeatureCoverageTest.php:1685-1686` explicitly asserts:
     ```php
     $this->assertEquals(200, $data['code'] ?? null);
     $this->assertEquals('OK', $data['desc'] ?? null);
     ```
4. **OpenAPI Documentation Status**:
   - `composer.json` contains `laravel/framework: ^13.30` and does not yet include `dedoc/scramble`.
   - Dry-run verification confirms `dedoc/scramble:^0.13.47` installs cleanly.
5. **Frontend Composables & Components**:
   - Directory `resources/js/composables/` does not exist.
   - `resources/js/components/telemetry/LiveTelemetry.vue` does not exist (`resources/js/views/LiveTelemetry.vue` exists but `test_f41` checks `resources/js/components/telemetry/LiveTelemetry.vue`).
   - `resources/js/components/employees/EmployeeFormModal.vue:470-481` implements webcam square crop using canvas offset `80, 0, 480, 480`, which can be cleanly extracted into `useBiometricCapture.js`.
   - `npm run build` succeeds cleanly in 1.39s building 26 chunks.

---

## 2. Logic Chain
1. **Premise 1 (Dual-Compatible Envelope)**:
   - Observation 1 & 2 show that `test_f34` asserts envelope keys (`$data['success']`, `$data['message']`, `$data['data']['id']`, `$data['meta']`), while existing tests (`DeviceManagementTest.php:74`) assert direct root keys (`assertJson(['id' => $device->id])`), and Vue views inspect pagination keys (`res.data.current_page`).
   - *Inference*: `ApiResponse::success()` and `ApiResponse::paginated()` must merge data keys into the response array while preserving `'success'`, `'message'`, `'data'`, and `'meta'`. For paginators, `'current_page'`, `'last_page'`, `'per_page'`, `'total'`, `'from'`, `'to'` must be placed at the root level alongside `'data'` and `'meta'`.
2. **Premise 2 (Hardware Protocol Immunity)**:
   - Observation 3 shows that `HttpWebhookController` serves embedded hardware firmware. Edge camera firmware crashes or disconnects if response JSON lacks top-level `code` and `desc`.
   - *Inference*: Any API envelope implementation must NOT touch `/Subscribe/*` or `/api/Subscribe/*`. These endpoints must remain entirely un-wrapped.
3. **Premise 3 (Form Request Decoupling)**:
   - Observation 2 reveals zero Form Requests in `app/Http/Requests/`. `test_f36` specifically checks `App\Http\Requests\StoreEmployeeRequest` and `App\Http\Requests\StoreDeviceRequest`.
   - *Inference*: Creating the 24 Form Requests across the 8 domain entities (Employee, Device, Personnel, Shift, Visitor, Leave, Attendance/Regularization, AccessGroup) with `authorize(): true` and moving the validation rules into `rules()` directly satisfies `test_f36`, decouples the controllers, and allows Scramble to automatically document request parameters.
4. **Premise 4 (Automated OpenAPI Generation)**:
   - Observation 4 shows `test_f37` requires `GET /docs/api` returning 200 OK.
   - *Inference*: Installing `dedoc/scramble`, publishing `config/scramble.php`, configuring `Gate::define('viewApiDocs', fn () => true)` in `AppServiceProvider.php`, and defining Bearer authentication (`SecurityScheme::http('bearer')`) will immediately activate `/docs/api` and pass `test_f37`.
5. **Premise 5 (Frontend Reusability & Test Compliance)**:
   - Observation 1 & 5 show that `test_f38`, `test_f39`, `test_f40`, and `test_f41` verify the physical existence of `usePaginatedResource.js`, `useLiveTelemetryStream.js`, `useBiometricCapture.js`, and `components/telemetry/LiveTelemetry.vue`.
   - *Inference*: Extracting the pagination, Echo, and webcam logic into these 3 composables, creating `resources/js/components/telemetry/LiveTelemetry.vue`, and referencing them in the views satisfies all four tests and guarantees Vite builds without regressions.

---

## 3. Caveats
1. **Camera Firmware Protocol Immutability**: Under no circumstances should `HttpWebhookController` return envelopes. The `code: 200, desc: "OK"` format is authoritative and frozen.
2. **Root Key Assertion Invariance**: When returning single resources (e.g. `DeviceController::show`), existing tests assert root keys. `ApiResponse::success($device->toArray())` or merging `$device->toArray()` into the response payload ensures 100% backward compatibility.
3. **Webcam MediaStream Leak Guard**: Hardware indicators stay active if tracks are not explicitly stopped. `useBiometricCapture` MUST invoke `track.stop()` in `onUnmounted()`.
4. **AudioContext Autoplay Policy**: Modern browsers restrict Web Audio API until a user interaction occurs. The synthesized chime must fail silently or gracefully resume on user click.

---

## 4. Conclusion

### Detailed Specification for M6 Implementation

#### 1. `App\Http\Responses\ApiResponse` Specification
- **File**: `app/Http/Responses/ApiResponse.php`
- **Public Methods**:
  - `success(mixed $data = null, string $message = 'Success', int $status = 200, array $meta = []): JsonResponse`
    - Payload construction:
      ```php
      $metaData = array_merge([
          'timestamp' => now()->toIso8601String(),
          'version' => 'v1',
      ], $meta);

      $payload = [
          'success' => true,
          'message' => $message,
          'data' => $data,
          'meta' => $metaData,
      ];

      // Dual-compatibility: merge associative array keys at root for legacy assertions
      if (is_array($data) && !array_is_list($data)) {
          $payload = array_merge($data, $payload);
      } elseif ($data instanceof \Illuminate\Database\Eloquent\Model) {
          $payload = array_merge($data->toArray(), $payload);
      }

      return response()->json($payload, $status);
      ```
  - `error(string $message = 'Error', int $status = 400, mixed $errors = null, array $meta = []): JsonResponse`
    - Payload construction:
      ```php
      $payload = [
          'success' => false,
          'message' => $message,
          'errors' => $errors,
          'meta' => array_merge([
              'timestamp' => now()->toIso8601String(),
              'version' => 'v1',
          ], $meta),
      ];
      return response()->json($payload, $status);
      ```
  - `paginated(\Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator, string $message = 'Success', int $status = 200, array $meta = []): JsonResponse`
    - Payload construction:
      ```php
      $paginatedArray = $paginator->toArray();
      $paginationMeta = [
          'current_page' => $paginator->currentPage(),
          'last_page' => $paginator->lastPage(),
          'per_page' => $paginator->perPage(),
          'total' => $paginator->total(),
          'from' => $paginator->firstItem(),
          'to' => $paginator->lastItem(),
      ];

      $payload = [
          'success' => true,
          'message' => $message,
          'data' => $paginatedArray['data'] ?? [],
          'meta' => array_merge([
              'timestamp' => now()->toIso8601String(),
              'version' => 'v1',
              'pagination' => $paginationMeta,
          ], $meta),
          // Root level backward compatibility
          'current_page' => $paginator->currentPage(),
          'last_page' => $paginator->lastPage(),
          'per_page' => $paginator->perPage(),
          'total' => $paginator->total(),
          'from' => $paginator->firstItem(),
          'to' => $paginator->lastItem(),
      ];

      return response()->json($payload, $status);
      ```

#### 2. The 24 Form Request Classes
All classes live in `app/Http/Requests/`, extend `Illuminate\Foundation\Http\FormRequest`, and return `true` from `authorize()`:
1. `StoreEmployeeRequest` (`first_name`, `employee_code`, `work_email`, etc.)
2. `UpdateEmployeeRequest` (same with unique ignore)
3. `AssignEmployeeShiftRequest` (`shift_id`, `effective_from`, `effective_to`, `assigned_days`)
4. `ImportEmployeesRequest` (`file` mimes:csv,txt)
5. `StoreDeviceRequest` (`device_id`, `name`, `ip_address`, `port`, `device_type`, `is_active`)
6. `UpdateDeviceRequest` (same with `name` sometimes required)
7. `SetSysTimeRequest` (`time`)
8. `UpMqttConfigRequest` (`MQEnable`, `MQAddr`, `MQPort`, `MQTopic`, `KeepAliveInterval`, etc.)
9. `StorePersonnelRequest` (`customize_id`, `name`, `person_type`, `gender`, `photo`, `photo_base64`)
10. `UpdatePersonnelRequest` (`name`, `person_type`, etc.)
11. `BulkPersonnelSyncRequest` (`personnel_ids`, `device_id`)
12. `BulkPersonnelDeleteRequest` (`personnel_ids`)
13. `StoreShiftRequest` (`name`, `code`, `shift_start`, `shift_end`, `grace_period_minutes`)
14. `UpdateShiftRequest` (same with unique ignore)
15. `AssignShiftRequest` (`employee_ids`, `effective_from`, `effective_to`)
16. `BulkAssignShiftRequest` (`shift_id`, `employee_ids`, `department_ids`, `effective_from`)
17. `StoreVisitorRequest` (`first_name`, `last_name`, `email`, `phone`, `company`)
18. `UpdateVisitorRequest` (`first_name`, `email`, `phone`)
19. `PreRegisterVisitorRequest` (`visitor_id`, `host_employee_id`, `purpose`, `expected_arrival`)
20. `CheckInVisitorRequest` (`badge_number`, `nda_signed`)
21. `StoreLeaveTypeRequest` (`name`, `code`, `max_days_per_year`, `is_paid`)
22. `UpdateLeaveTypeRequest` (`name`, `code`)
23. `StoreLeaveRequest` (`employee_id`, `leave_type_id`, `start_date`, `end_date`, `reason`)
24. `StoreAccessGroupRequest` (`name`, `code`, `device_ids`, `personnel_ids`, `department_ids`)
*(Companion Form Requests: `UpdateAccessGroupRequest`, `StoreRegularizationRequest`, `ManualAttendancePunchRequest`)*

#### 3. OpenAPI Documentation (`dedoc/scramble`)
- Package: `dedoc/scramble`
- Config: `config/scramble.php`
- Setup in `AppServiceProvider.php`:
  ```php
  use Dedoc\Scramble\Scramble;
  use Dedoc\Scramble\Support\Generator\OpenApi;
  use Dedoc\Scramble\Support\Generator\SecurityScheme;
  use Illuminate\Support\Facades\Gate;

  Gate::define('viewApiDocs', function ($user = null) {
      return true;
  });

  Scramble::afterOpenApiGenerated(function (OpenApi $openApi) {
      $openApi->secure(
          SecurityScheme::http('bearer')
      );
  });
  ```

#### 4. Frontend Composables
1. `resources/js/composables/usePaginatedResource.js`:
   - Universal parser handling both envelope `{ success, data: [...], meta: ... }` and direct `{ data: [...], current_page: ... }`.
   - Debounced search query watcher (300ms).
   - Returns reactive items, pagination, loading, error, and navigation actions.
2. `resources/js/composables/useLiveTelemetryStream.js`:
   - Echo listener on `telemetry`, `access-logs`, `device-alerts`.
   - Web Audio synthesizer oscillator (`AudioContext`) for chime alerts.
   - Bounded buffer (max 50) and deduplication.
3. `resources/js/composables/useBiometricCapture.js`:
   - `getUserMedia` webcam stream with 1:1 centered square canvas cropping.
   - `capturePhoto()` generates base64 data URL.
   - `onUnmounted()` halts active media tracks.

#### 5. Frontend View Refactoring
- Create `resources/js/components/telemetry/LiveTelemetry.vue` (satisfies `test_f41`).
- Refactor `views/LiveTelemetry.vue` to consume `usePaginatedResource` and `useLiveTelemetryStream`.
- Refactor `components/employees/EmployeeFormModal.vue` and `components/visitors/VisitorCheckInWizard.vue` to consume `useBiometricCapture`.
- Refactor `views/AccessLogsHistory.vue`, `views/StrangerSnapsMonitor.vue`, `views/DeviceAlertsCenter.vue`, `views/PersonnelManager.vue`, `views/DeviceManager.vue`, and `views/VisitorDashboard.vue` to consume `usePaginatedResource`.

---

## 5. Verification Method

1. **Verify All Milestone 6 Tests Pass**:
   ```bash
   php artisan test --filter="test_f34|test_f35|test_f36|test_f37|test_f38|test_f39|test_f40|test_f41"
   ```
   *Expected outcome*: 8 tests, 8 passed, 0 failures, 0 skipped.

2. **Verify Full Test Suite Remains Green (0 Regressions)**:
   ```bash
   php artisan test
   ```
   *Expected outcome*: 749 tests, 747 passed, 0 failures, 2 skipped (only M7 skipped).

3. **Verify Interactive OpenAPI Endpoints**:
   ```bash
   curl -s -I http://localhost:8000/docs/api
   curl -s -I http://localhost:8000/docs/api.json
   ```
   *Expected outcome*: Both return HTTP 200 OK.

4. **Verify Frontend Build**:
   ```bash
   npm run build
   ```
   *Expected outcome*: Vite compiles cleanly in < 1.5s with 0 syntax or bundling errors.
