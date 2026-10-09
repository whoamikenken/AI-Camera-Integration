# HANDOFF — Explorer Survey 3: R7 & R8 Architectural Survey

## 1. Observation

1. **Current Codebase Test Suite & Build State**:
   - `php artisan test`: Execution completed cleanly in 62.1 seconds with output:
     ```json
     {"tool":"phpunit","result":"passed","tests":360,"passed":358,"assertions":1472,"duration_ms":62116,"skipped":2}
     ```
   - `npm run build`: Vite `v8.2.2` transformed 137 modules and built all 25 production chunks cleanly in **670ms** without warnings or errors.
2. **Missing Form Requests Directory**:
   - `app/Http/` contains only `Controllers/` and `Middleware/`. Directory `app/Http/Requests/` does not exist at all (`list_dir` on `app/Http` returned 2 subdirectories).
   - Domain controllers execute heavy inline validation:
     - `EmployeeController.php:76-100`: 25 lines of inline `$request->validate()` for `store()`, and lines 160-184 for `update()`.
     - `DeviceController.php:55-71`: 17 lines of inline `$request->validate()` for `store()`, and lines 131-146 for `update()`.
     - `VisitorController.php:49-61`: 13 lines of inline validation for `store()`, lines 82-94 for `update()`, lines 151-157 for `preRegister()`, and lines 197-200 for `checkIn()`.
     - `ShiftController.php:42-65`: 24 lines of inline validation for `store()`, lines 99-122 for `update()`.
     - `LeaveController.php:38-48`: 11 lines of inline validation for `storeLeaveType()`, lines 177-183 for `storeRequest()`.
     - `PersonnelController.php:55-72`: 18 lines of inline validation for `store()`, lines 114-130 for `update()`.
3. **Response Envelope Inconsistencies**:
   - Raw paginators: `EmployeeController.php:71` (`return response()->json($employees);`), `AccessLogController.php:51` (`return response()->json($logs);`), `StrangerSnapController.php:29` (`return response()->json($snaps);`), `PersonnelController.php:50` (`return response()->json($personnel);`), `VisitorController.php:44` (`return response()->json($query->orderBy(...)->paginate($perPage));`).
   - Bare models without envelopes: `DeviceController.php:90` (`return response()->json($device, 201);`), `DeviceController.php:121` (`return response()->json(array_merge($device->toArray(), ...));`), `DeviceController.php:158` (`return response()->json($device);`).
   - Partial envelopes: `EmployeeController.php:128` (`return response()->json(['message' => '...', 'data' => $employee], 201);`), `EmployeeController.php:153` (`return response()->json(['data' => $employee]);`).
   - Full envelopes: `OrganizationController.php:43` (`return response()->json(['success' => true, 'data' => $orgs]);`), `RoleController.php:20` (`return response()->json(['success' => true, 'data' => $roles]);`).
4. **Hardware Protocol Invariant**:
   - `HttpWebhookController.php:226-231` returns:
     ```php
     return response()->json([
         'code' => 200,
         'desc' => 'OK (Duplicate ignored)',
         'info' => ['Result' => 'Ok'],
     ]);
     ```
   - Hardware endpoints `/Subscribe/*` serve edge camera hardware directly and must be excluded from REST response wrapping.
5. **OpenAPI Package Status**:
   - `composer.json` does not include `dedoc/scramble`.
   - Dry-run verification `composer require --dry-run dedoc/scramble` confirmed version `v0.13.47` installs cleanly with zero dependency conflicts against Laravel `13.26.1`.
6. **Frontend State & Duplication**:
   - No composables directory exists at `resources/js/composables/`.
   - `LiveTelemetry.vue:255-263`, `AccessLogsHistory.vue:170-184`, `StrangerSnapsMonitor.vue:607-618`, `PersonnelManager.vue:300-310` duplicate identical pagination objects (`current_page`, `last_page`, `total`, `per_page`), loading indicators, and page navigation routines.
   - `EmployeeFormModal.vue:455-489` implements inline webcam acquisition, canvas frame rendering, and 480x480 square cropping; `VisitorCheckInWizard.vue` lacks webcam capture.

---

## 2. Logic Chain

1. **Premise 1 (API Uniformity)**: From Observation 3, callers currently experience unpredictable response structures: some endpoints return `{ data: [...] }`, some `{ current_page: 1, data: [...] }`, some bare `{ id: 1, ... }`, and some `{ success: true, data: [...] }`.
2. **Premise 2 (Regression Prevention)**: From Observation 1, 358 PHPUnit tests pass. Some tests specifically assert top-level keys (e.g. `DeviceManagementTest::test_can_get_device_detail` asserts `$response->assertJson(['id' => $device->id])`), while others assert paginator arrays via `$response->json('data')`.
3. **Inference 1 (Dual-Compatible Envelope)**: Designing `ApiResponse::paginated()` to return `{ success: true, data: [...], meta: { ... }, current_page: 1, last_page: 1, per_page: 15, total: 100 }` simultaneously satisfies modern REST envelope criteria and prevents breaking any existing frontend components or tests expecting root pagination properties.
4. **Premise 3 (Form Request Cleanliness)**: From Observation 2, over 200 lines of validation boilerplate reside inside controller methods, obscuring business logic and making OpenAPI documentation inference difficult.
5. **Inference 2 (Modular Extraction)**: Extracting validation into 24 distinct Form Request classes under `app/Http/Requests/` decouples HTTP request validation, enables automated Scramble schema extraction, and preserves identical validation rules and error codes.
6. **Premise 4 (Hardware Isolation)**: From Observation 4, camera hardware endpoints (`/Subscribe/*`) follow X40Y embedded firmware protocols.
7. **Inference 3 (Hardware Boundary)**: Isolating `HttpWebhookController` from the uniform envelope refactor is mandatory to avoid hardware push rejections and connection drops.
8. **Premise 5 (Frontend Reusability)**: From Observation 6, 5 distinct views duplicate pagination logic, and 2 distinct modal workflows need webcam biometric capture.
9. **Inference 4 (Composable Extraction)**: Creating `usePaginatedResource`, `useLiveTelemetryStream`, and `useBiometricCapture` in `resources/js/composables/` directly removes ~250 lines of duplicate code, standardizes WebSocket lifecycle management, and enables webcam capture in the visitor check-in wizard.

---

## 3. Caveats

1. **Hardware Camera Firmware Dependency**: Edge cameras (`X40Y`) communicate over HTTP Webhooks without standard browser headers. Under no circumstances should `HttpWebhookController` response structures be modified.
2. **Top-Level Root Keys on Legacy Device Endpoints**: For `DeviceController::show` and `DeviceController::store`, top-level attributes must be retained alongside `"data"` to ensure strict backward compatibility with existing tests in `DeviceManagementTest.php`.
3. **Webcam MediaStream Teardown**: Browsers require explicit `track.stop()` invocation on all active video tracks to extinguish the camera hardware indicator; `useBiometricCapture` must bind this to `onUnmounted()`.
4. **API Versioning**: Route aliases under `/api/v1/*` should map directly to `/api/*` controller actions without deprecating root `/api/*` routes.

---

## 4. Conclusion

1. **R7 Implementation Strategy**:
   - Implement `App\Http\Responses\ApiResponse` to generate standardized responses:
     - Single resources: `{ success: true, message: "...", data: {...}, meta: {...} }`
     - Paginated resources: `{ success: true, data: [...], meta: {...}, current_page, last_page, per_page, total }`
     - Errors: `{ success: false, message: "...", errors: {...}, meta: {...} }`
   - Create 24 Form Request classes across `Employee`, `Device`, `Shift`, `Visitor`, `Leave`, `Personnel`, `Organization`, and `Regularization` domains.
   - Install `dedoc/scramble:^0.13.47`, publish `config/scramble.php`, and configure Sanctum Bearer security in `AppServiceProvider.php` to serve `/docs/api` and `/docs/api.json`.
   - Exempt `HttpWebhookController` to maintain strict hardware protocol compatibility.
2. **R8 Implementation Strategy**:
   - Create `resources/js/composables/usePaginatedResource.js` handling pagination, reactive filters, and debounced queries.
   - Create `resources/js/composables/useLiveTelemetryStream.js` managing private Echo channels, Reverb events, deduplication, and synthesized chime alerts.
   - Create `resources/js/composables/useBiometricCapture.js` providing webcam streaming, square canvas cropping (1:1), and file conversion.
   - Refactor `LiveTelemetry.vue`, `AccessLogsHistory.vue`, `StrangerSnapsMonitor.vue`, `DeviceAlertsCenter.vue`, `PersonnelManager.vue`, `EmployeeFormModal.vue`, and `VisitorCheckInWizard.vue` to adopt these composables.

---

## 5. Verification Method

1. **Run Full Automated Test Suite**:
   ```bash
   php artisan test
   ```
   *Expected Outcome:* All 360 tests execute cleanly with 358 passing and 0 failures.
2. **Run Frontend Production Build**:
   ```bash
   npm run build
   ```
   *Expected Outcome:* Vite builds successfully in < 1 second with 0 bundling or syntax errors.
3. **Verify Scramble Documentation Endpoints**:
   ```bash
   curl -s -I http://localhost:8000/docs/api
   curl -s -I http://localhost:8000/docs/api.json
   ```
   *Expected Outcome:* Both return HTTP 200 OK with valid HTML/JSON payloads.
4. **Inspect Code Architecture & Layout**:
   - Check that `app/Http/Requests/` contains modular Form Request classes.
   - Check that `resources/js/composables/` contains the 3 composables.
   - Ensure zero source or test code was placed in `.agents/teamwork/`.
