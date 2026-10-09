# DISPATCH DIRECTIVE — worker_m6_1_rep (M6 Implementation Worker)

## Mission
Implement Milestone M6 (API Uniformity, Form Requests, Scramble OpenAPI & Frontend Composables: Features #34 through #41) per `system-evo.md`, `orchestrator_11/PROJECT.md`, and explorer handoff reports.

## Parent Information
- Parent Conversation ID: `2af1d024-aed2-4512-af6e-93c099256b99` (Project Orchestrator 12)
- Working directory: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m6_1_rep`

## MANDATORY INTEGRITY WARNING
> DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A teamwork_preview_auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

## Mandatory First Steps
Read the following authoritative documents before modifying code:
1. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (under Section `## 2026-10-07T01:57:58Z`)
2. `/home/wsk-devops2/AI-Camera-Integration/system-evo.md` (Areas 4 & 5: API Uniformity & Documentation, and Frontend Composables & Reactive Architecture)
3. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md` (Milestone M6: Features #34 through #41)
4. Explorer Handoff Reports:
   - `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/spec_miner_m6_1/handoff.md`
   - `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m6_backend/handoff.md`
   - `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m6_frontend/handoff.md`

## File Ownership
You have exclusive write ownership of:
- `app/Http/Responses/ApiResponse.php`
- `app/Http/Requests/*` (all 24 Form Request classes)
- `app/Providers/AppServiceProvider.php` (for Scramble gate & Bearer security)
- `config/scramble.php`
- `resources/js/composables/usePaginatedResource.js`
- `resources/js/composables/useLiveTelemetryStream.js`
- `resources/js/composables/useBiometricCapture.js`
- `resources/js/components/telemetry/LiveTelemetry.vue`
- Domain controllers needing Form Request injection and `ApiResponse`
- Vue views consuming composables

## Detailed Task Breakdown

### 1. Standard API Response Envelope (`app/Http/Responses/ApiResponse.php`) [Feature #34]
- Create `app/Http/Responses/ApiResponse.php` with:
  - `ApiResponse::success(mixed $data = null, ?string $message = 'Success', int $status = 200, array $meta = []): JsonResponse`
  - `ApiResponse::paginated(AbstractPaginator $paginator, ?string $message = 'Success', int $status = 200, array $meta = []): JsonResponse`
  - `ApiResponse::error(string $message = 'Error', int $status = 400, mixed $errors = null, array $meta = []): JsonResponse`
  - `ApiResponse::created(mixed $data = null, ?string $message = 'Resource created successfully.', array $meta = []): JsonResponse`
  - `ApiResponse::message(string $message, int $status = 200, array $meta = []): JsonResponse`
  - `ApiResponse::noContent(): JsonResponse`
- **Dual-Compatibility Requirements**:
  - Root envelope contains `success: true`, `message`, `data`, and `meta: { timestamp, version }`.
  - For single models or associative arrays, merge attributes at root level (avoiding overwrite of envelope keys) so tests asserting `$response->assertJson(['id' => 1])` pass.
  - For paginators, merge the paginator array (`current_page`, `last_page`, `per_page`, `total`, `from`, `to`, `data`) at the root level while setting `meta.pagination`, so tests asserting top-level paginator keys pass seamlessly.
  - For errors, provide root-level `'errors'` when `$errors !== null` so `$response->assertJsonValidationErrors(...)` passes.
  - Prevent double `'data'` nesting if `$data` already contains `['data' => ...]`.

### 2. Hardware Webhook Protocol Exemption [Feature #35]
- **DO NOT** wrap `/Subscribe/*` or `api/Subscribe/*` endpoints in `HttpWebhookController.php` with `ApiResponse`. Edge camera firmware requires strict HTTP Protocol V1.13 raw format (`{"code": 200, "desc": "OK", "info": ...}`). Preserve raw responses.

### 3. Dedicated Form Requests (24 Classes in `app/Http/Requests/`) [Feature #36]
Create directory `app/Http/Requests/` with 24 Form Request classes (all with `public function authorize(): bool { return true; }` and extraction of existing controller validation rules):
1. `StoreEmployeeRequest` (`first_name`, `employee_code`, `department_id`, etc.)
2. `UpdateEmployeeRequest`
3. `AssignShiftRequest`
4. `BulkAssignDepartmentRequest`
5. `StoreDeviceRequest` (`device_id`, `name`, `ip_address`, `port`, `device_type`, `is_active`)
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
(Plus companion `StorePersonnelRequest`, `UpdatePersonnelRequest`, `BulkSyncPersonnelRequest`, `BulkDeletePersonnelRequest` as appropriate).
- Inject these Form Requests into corresponding controller methods.

### 4. Dedoc Scramble OpenAPI Documentation [Feature #37]
- Check if `dedoc/scramble` is installed; run `composer require dedoc/scramble` if needed.
- Publish scramble config if needed (`php artisan vendor:publish --provider="Dedoc\Scramble\ScrambleServiceProvider" --tag="scramble-config"`).
- In `app/Providers/AppServiceProvider.php`, define the gate and Bearer token security:
  ```php
  use Illuminate\Support\Facades\Gate;
  use Dedoc\Scramble\Scramble;
  use Dedoc\Scramble\Support\Generator\OpenApi;
  use Dedoc\Scramble\Support\Generator\SecurityScheme;

  Gate::define('viewApiDocs', function ($user = null) {
      return true; // Allows testing environment and admin access
  });

  Scramble::afterOpenApiGenerated(function (OpenApi $openApi) {
      $openApi->secure(
          SecurityScheme::http('bearer')
      );
  });
  ```
- Ensure `GET /docs/api` returns HTTP 200 OK.

### 5. Frontend Composables [Features #38, #39, #40]
Create `resources/js/composables/`:
1. `resources/js/composables/usePaginatedResource.js`:
   - Universal response parser handling wrapped `ApiResponse`, standard Laravel paginator, API resource collections, and flat arrays.
   - Built-in 300ms debounced search, `page`, `perPage`, `filters`, `sort`, `pagination`, `changePage`, `nextPage`, `prevPage`, `refresh`, `mutate`.
2. `resources/js/composables/useLiveTelemetryStream.js`:
   - Reverb/Echo channel listener for `access-logs`, `device-alerts`, `stranger-snaps`, `attendance`, `sync-tasks`.
   - Web Audio synthesizer: harmonic sine dual-tone chime (523.25Hz -> 659.25Hz) for allowed verifications, sawtooth pulse (440Hz -> 220Hz) for denied/critical events.
   - Bounded buffer (max 50) and deduplication. Automatic unmount cleanup (`leave`).
3. `resources/js/composables/useBiometricCapture.js`:
   - Webcam stream acquisition (`getUserMedia`), centered 1:1 square canvas crop, minimum 200x200 px validation.
   - Export both Data URL and raw Base64. `processImageFile(file)` for file upload cropping.
   - Unmount track cleanup (`track.stop()`).

### 6. Frontend Views & Proxy Component [Feature #41]
- Create proxy `resources/js/components/telemetry/LiveTelemetry.vue` wrapping `views/LiveTelemetry.vue` (satisfies `test_f41`).
- Refactor views (`views/LiveTelemetry.vue`, `views/AccessLogsHistory.vue`, `views/StrangerSnapsMonitor.vue`, `components/employees/EmployeeFormModal.vue`, etc.) to consume the composables cleanly.
- Run `npm run build` and ensure Vite compiles with exit code 0 and 0 errors.

## Verification Requirements
You must execute and report the exact results of:
1. `php artisan test --filter="test_f3[4-9]|test_f4[0-1]"`
2. `php artisan test --filter=Tier1FeatureCoverageTest`
3. `php artisan test --filter=E2E`
4. `php artisan test` (must pass 100% of tests with 0 failures)
5. `npm run build` (must compile cleanly with exit code 0)

## Handoff Report
Write your full report to:
`/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m6_1_rep/handoff.md`
Report back to parent (`2af1d024-aed2-4512-af6e-93c099256b99`) via `send_message`.

## 2026-10-09T04:27:30Z
You are M6 Implementation Worker (worker_m6_1_rep).
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m6_1_rep
Your dispatch directive is at: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m6_1_rep/DISPATCH.md
Read DISPATCH.md, ORIGINAL_REQUEST.md, system-evo.md, and the explorer handoff reports (spec_miner_m6_1/handoff.md, explorer_m6_backend/handoff.md, explorer_m6_frontend/handoff.md).

MANDATORY INTEGRITY WARNING:
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A forensic auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

Implement Milestone M6 (Features #34 through #41):
1. Create app/Http/Responses/ApiResponse.php with dual compatibility for models, paginators, and errors.
2. Preserve raw hardware webhook protocol on /Subscribe/* in HttpWebhookController.php.
3. Create 24 Form Request classes in app/Http/Requests/ and type-hint in domain controllers.
4. Install/configure dedoc/scramble OpenAPI docs at /docs/api with Bearer security.
5. Create frontend composables in resources/js/composables/ (usePaginatedResource.js, useLiveTelemetryStream.js, useBiometricCapture.js).
6. Create proxy component resources/js/components/telemetry/LiveTelemetry.vue and refactor views.
7. Run and verify:
   - php artisan test --filter="test_f3[4-9]|test_f4[0-1]"
   - php artisan test --filter=Tier1FeatureCoverageTest
   - php artisan test --filter=E2E
   - php artisan test (all tests passing)
   - npm run build (clean build, exit code 0)

Write your full completion report to:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m6_1_rep/handoff.md
Send a completion message back to parent (2af1d024-aed2-4512-af6e-93c099256b99) via send_message when finished.
