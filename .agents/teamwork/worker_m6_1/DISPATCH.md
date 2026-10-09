# DISPATCH DIRECTIVE — worker_m6_1

## Identity
- **Agent:** `worker_m6_1`
- **Role:** Implementation Worker (Milestone M6: API Uniformity, Form Requests, Scramble OpenAPI & Composables)
- **Working Directory:** `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m6_1`
- **Parent Conversation ID:** `340b2ee2-86ac-4ca7-9f71-8c1542c65adb`

---

## Mandatory Integrity Warning
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A teamwork_preview_auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

---

## Authoritative Inputs to Read First
1. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (under Section `## 2026-10-07T01:57:58Z`)
2. `/home/wsk-devops2/AI-Camera-Integration/system-evo.md` (Areas 4 & 5: API Uniformity & Documentation, and Frontend Composables & Reactive Architecture)
3. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md` (Milestone M6: Features #34 through #41)
4. Explorer Handoff Reports:
   - `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/spec_miner_m6_1/handoff.md`
   - `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m6_backend/handoff.md`
   - `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m6_frontend/handoff.md`

---

## Tasks to Implement

### 1. Standard API Response Envelope (`ApiResponse.php`) [Feature #34]
- Create `app/Http/Responses/ApiResponse.php`:
  - Methods: `success(mixed $data = null, ?string $message = null, int $status = 200, array $meta = []): JsonResponse`
  - `paginated(AbstractPaginator $paginator, ?string $message = null, int $status = 200, array $meta = []): JsonResponse`
  - `error(string $message, int $status = 400, mixed $errors = null, array $meta = []): JsonResponse`
  - `created()`, `message()`, `noContent()`.
  - **CRITICAL DUAL-COMPATIBILITY REQUIREMENTS**:
    - For paginators: merge root paginator array with envelope (`current_page`, `last_page`, `total`, `per_page`, `data`, etc.) so tests asserting `$response->json('current_page')` or `assertJsonStructure(['current_page', 'data', ...])` pass.
    - For associative data: place into `'data'` AND merge non-conflicting model attributes into root level so `$response->assertJson(['id' => 1])` passes without breaking.
    - Prevent double nesting: if `['data' => $val]` is passed, unwrap to avoid `{"data": {"data": ...}}`.
    - In `error()`: include root-level `'errors'` when provided so `$response->assertJsonValidationErrors(...)` passes.

### 2. Hardware Webhook Protocol Exemption [Feature #35]
- Verify that `HttpWebhookController.php` routes (`/Subscribe/heartbeat`, `/Subscribe/Verify`, `/Subscribe/Snap`) remain completely exempt from `ApiResponse` and continue returning raw HTTP Protocol V1.13 JSON packets (`code: 200, desc: 'OK', info: ...`).
- Verify `test_f35` passes.

### 3. Dedicated Form Request Classes (24 Classes) [Feature #36]
- Create directory `app/Http/Requests/` and implement 24 Form Request classes (in namespace `App\Http\Requests`):
  1. `StoreEmployeeRequest`
  2. `UpdateEmployeeRequest`
  3. `AssignShiftRequest`
  4. `BulkAssignDepartmentRequest`
  5. `StoreDeviceRequest`
  6. `UpdateDeviceRequest`
  7. `BulkRebootDeviceRequest`
  8. `BulkSyncMqttDeviceRequest`
  9. `StoreShiftRequest`
  10. `UpdateShiftRequest`
  11. `BulkAssignShiftRequest`
  12. `StoreVisitorRequest`
  13. `UpdateVisitorRequest`
  14. `CreateVisitRequest`
  15. `CheckInVisitRequest`
  16. `CancelVisitRequest`
  17. `StoreLeaveTypeRequest`
  18. `SubmitLeaveRequest`
  19. `ReviewLeaveRequest`
  20. `CancelLeaveRequest`
  21. `StoreAttendancePunchRequest`
  22. `SubmitRegularizationRequest`
  23. `StoreAccessGroupRequest`
  24. `UpdateAccessGroupRequest`
  *(Plus companion `StorePersonnelRequest`, `UpdatePersonnelRequest`, `BulkSyncPersonnelRequest`, `BulkDeletePersonnelRequest`)*.
- In each Form Request, set `public function authorize(): bool { return true; }` and implement `rules(): array` duplicating the exact controller validation rules.
- Type-hint these Form Requests in the corresponding controller methods (e.g., `EmployeeController::store(StoreEmployeeRequest $request)`, `DeviceController::store(StoreDeviceRequest $request)`).

### 4. Dedoc Scramble OpenAPI Integration at `/docs/api` [Feature #37]
- Install Dedoc Scramble: `composer require dedoc/scramble`.
- Publish Scramble config if needed: `php artisan vendor:publish --provider="Dedoc\Scramble\ScrambleServiceProvider" --tag="scramble-config"`.
- In `app/Providers/AppServiceProvider.php`, define the `viewApiDocs` gate:
  ```php
  Gate::define('viewApiDocs', function (?User $user = null) {
      return app()->environment('local', 'testing')
          || ($user !== null && ($user->hasRole('super-admin') || $user->hasRole('admin')));
  });
  ```
  Configure OpenAPI Bearer token security scheme via `Scramble::configure()`.
- Verify `GET /docs/api` returns HTTP 200 OK.

### 5. Frontend Vue 3 Composables [Features #38, #39, #40]
- Create `resources/js/composables/usePaginatedResource.js`:
  - Implements universal response normalizer handling wrapped `ApiResponse`, standard paginator, resource collection with `meta`, or flat array.
  - Implements debounced search query (300ms), bounds-checked pagination (`changePage`, `nextPage`, `prevPage`), reactive filters and sorting, and reactive `mutate` method.
- Create `resources/js/composables/useLiveTelemetryStream.js`:
  - Implements private/public Echo channel listener with event buffering and deduplication.
  - Implements Web Audio API dual-harmonic synthesized chime (no external audio assets).
  - Implements automatic channel cleanup on `onUnmounted`.
- Create `resources/js/composables/useBiometricCapture.js`:
  - Implements camera hardware stream management via `navigator.mediaDevices.getUserMedia`.
  - Implements dynamic 1:1 square center crop for any source video aspect ratio.
  - Implements minimum dimension validation (200x200 px).
  - Exports data URL and raw Base64 string.
  - Implements file upload processing (`processImageFile`).

### 6. Frontend View Refactoring & Component Proxy [Feature #41]
- Create component proxy `resources/js/components/telemetry/LiveTelemetry.vue` importing and rendering `../../views/LiveTelemetry.vue` (satisfies `test_f41`).
- Refactor target views (`LiveTelemetry.vue`, `AccessLogsHistory.vue`, `StrangerSnapsMonitor.vue`, `DeviceAlertsCenter.vue`, `SyncTasksMonitor.vue`, `PersonnelManager.vue`, `EmployeeFormModal.vue`) to leverage the composables where applicable.
- Verify `npm run build` succeeds cleanly in < 1.5s with exit code 0.

---

## Verification Commands
Run the following verification commands and record outputs in your handoff report:
1. `php artisan test --filter="test_f3[4-9]|test_f4[0-1]"`
2. `php artisan test --filter=Tier1FeatureCoverageTest`
3. `php artisan test --filter=E2E`
4. `php artisan test` (Verify 100% pass across all 740+ tests, 0 failures!)
5. `npm run build` (Verify clean bundle compilation with exit code 0)

---

## Output Requirements
Write your detailed report to `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m6_1/handoff.md`.
Notify parent (`340b2ee2-86ac-4ca7-9f71-8c1542c65adb`) via `send_message`.


## 2026-10-09T00:48:41Z
Received dispatch message from parent 340b2ee2-86ac-4ca7-9f71-8c1542c65adb:
Implement all 6 task areas detailed in DISPATCH.md and the explorer blueprints:
1. Standard API Response Envelope app/Http/Responses/ApiResponse.php with dual-compatibility for tests (merging root model attributes and root pagination fields while wrapping data/meta).
2. Hardware Webhook Protocol Exemption: ensure /Subscribe/* endpoints in HttpWebhookController remain untouched and exempt from ApiResponse.
3. Dedicated Form Request classes in app/Http/Requests/ (all 24 Form Requests across Employee, Device, Shift, Visitor, Leave, Attendance, AccessGroup, Personnel) and type-hint in controller methods.
4. Dedoc Scramble OpenAPI documentation: install dedoc/scramble via composer, publish config, configure viewApiDocs gate in AppServiceProvider for testing/local and admin roles, configure bearer auth scheme, verify /docs/api returns 200 OK.
5. Frontend Composables:
   - resources/js/composables/usePaginatedResource.js
   - resources/js/composables/useLiveTelemetryStream.js
   - resources/js/composables/useBiometricCapture.js
6. Frontend view refactoring and component proxy:
   - Create proxy resources/js/components/telemetry/LiveTelemetry.vue
   - Refactor views to consume composables cleanly.
   - Run npm run build and verify clean build.
