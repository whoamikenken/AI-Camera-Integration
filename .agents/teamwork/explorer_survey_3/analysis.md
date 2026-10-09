# Comprehensive Technical Survey & Architectural Specification: R7 & R8
**Domain / Scope:** R7 (API Response Uniformity, Form Requests & Scramble OpenAPI) & R8 (Frontend Composable Architecture)  
**Date:** October 7, 2026  
**Investigator:** `explorer_survey_3`  
**Reference Sources:** `system-evo.md` (Areas 4 & 5), `ORIGINAL_REQUEST.md` (## 2026-10-07T01:57:58Z), `routes/api.php`, `app/Http/Controllers/`, `resources/js/`

---

## 1. Executive Summary

This survey provides the complete architectural blueprint and concrete gap analysis for two core pillars of the Intelligent AI Camera Hub platform evolution:
1. **R7: API Response Uniformity & Form Requests (Area 4 in `system-evo.md`)**:
   - Standardize all domain API responses into a unified, predictable envelope (`success`, `data`, `meta`, `message`, `errors`) across all domain controllers while preserving root `/api/*` endpoint paths and edge hardware protocol contracts.
   - Extract sprawling inline `$request->validate()` blocks across domain controllers into dedicated, modular Laravel Form Request classes (`app/Http/Requests/`).
   - Integrate automated OpenAPI 3.1 documentation generation via **Dedoc Scramble** (`dedoc/scramble` `v0.13.47`), serving interactive documentation at `/docs/api` and raw OpenAPI schema at `/docs/api.json` with Sanctum Bearer token security definitions.
2. **R8: Frontend Composable Architecture (Area 5 in `system-evo.md`)**:
   - Eliminate redundant pagination, search debounce, filter state, and response parsing boilerplate across 8+ views by introducing `usePaginatedResource(endpoint, initialFilters)`.
   - Centralize WebSocket subscriptions, event listener registration, message deduplication, and synthesized audio alerts across telemetry dashboards by introducing `useLiveTelemetryStream(channelName, eventHandlers)`.
   - Provide a shared, hardware-agnostic webcam acquisition, square cropping (aspect ratio 1:1), and file conversion composable `useBiometricCapture()` shared between `EmployeeFormModal.vue`, `VisitorCheckInWizard.vue`, `PersonnelManager.vue`, and `StrangerSnapsMonitor.vue`.

Baseline verification establishes that the current backend test suite passes completely (**358 tests, 1,472 assertions, 0 failures, 2 skipped**) and the frontend compiles cleanly via Vite in **670ms**. The architectural recommendations below are specifically designed to preserve this green state with **zero regressions**.

---

## 2. R7: API Response Uniformity, Form Requests & Scramble OpenAPI

### 2.1 Route Architecture & Protocol Tiers

An essential finding from the codebase inspection of `routes/api.php` is that endpoints are strictly divided into three distinct operational tiers:

| Tier | Endpoints | Authentication | Client / Consumer | Response Constraint |
| :--- | :--- | :--- | :--- | :--- |
| **Tier 1: Hardware Webhooks** | `POST /Subscribe/heartbeat`<br>`POST /Subscribe/Verify`<br>`POST /Subscribe/Snap` | Unauthenticated (Hardware Secret / IP Allowlist) | Edge Camera Firmware (X40Y IPCs) | **STRICT PROTOCOL EXEMPTION:** Must return `{"code": 200, "desc": "OK", "info": {...}}`. Wrapping in modern API envelopes breaks firmware push verification and drops hardware connections! |
| **Tier 2: Public Endpoints** | `POST /api/auth/login`<br>`GET /api/settings/public` | Unauthenticated (Rate limited) | Web Browsers & Mobile Clients | Standard API Envelope (`success`, `data`, `meta`) |
| **Tier 3: Guarded Domain Endpoints** | All 45+ endpoints under `auth:sanctum`, `active`, `throttle:api` | Authenticated (Sanctum Bearer) | Vue 3 SPA, Admin Console, External Integrations | Standard API Envelope (`success`, `data`, `meta`) |

### 2.2 Controller Response Inventory & Current Discrepancies

A systematic audit of all 23 controllers in `app/Http/Controllers/` reveals significant fragmentation in response formats:

| Controller | Method | Current Return Structure | Inconsistency Category | Target Uniform Format |
| :--- | :--- | :--- | :--- | :--- |
| `EmployeeController` | `index` | `response()->json($employees)` | Raw `LengthAwarePaginator` | `ApiResponse::paginated($employees)` |
| `EmployeeController` | `store` | `response()->json(['message' => '...', 'data' => $emp], 201)` | Partial envelope (missing `success`, `meta`) | `ApiResponse::success($emp, 'Employee created successfully.', 201)` |
| `EmployeeController` | `show` | `response()->json(['data' => $emp])` | Partial envelope (missing `success`, `meta`) | `ApiResponse::success($emp)` |
| `EmployeeController` | `destroy` | `response()->json(['message' => '...'])` | String message only | `ApiResponse::success(null, 'Employee deleted successfully.')` |
| `DeviceController` | `index` | `response()->json($devices)` | Flat array of objects | `ApiResponse::success($devices)` |
| `DeviceController` | `store` | `response()->json($device, 201)` | Bare Model object | `ApiResponse::success($device, 'Device created.', 201)` + legacy root keys |
| `DeviceController` | `show` | `response()->json(array_merge($device->toArray(), ...))` | Bare Model array | `ApiResponse::success($data)` + legacy root keys for tests |
| `DeviceController` | `update` | `response()->json($device)` | Bare Model object | `ApiResponse::success($device, 'Device updated.')` |
| `DeviceController` | `destroy` | `response()->json(['message' => '...'])` | String message only | `ApiResponse::success(null, 'Device deleted successfully.')` |
| `DeviceController` | `audit` | `response()->json(['success' => true, 'code' => 200, 'device' => ..., ...])` | Custom envelope | `ApiResponse::success($auditData)` |
| `PersonnelController`| `index` | `response()->json($personnel)` | Raw `LengthAwarePaginator` | `ApiResponse::paginated($personnel)` |
| `PersonnelController`| `show` | `response()->json($personnel)` | Bare Model object | `ApiResponse::success($personnel)` |
| `PersonnelController`| `store` | `response()->json($person, 201)` | Bare Model object | `ApiResponse::success($person, 'Personnel created.', 201)` |
| `AccessLogController`| `index` | `response()->json($logs)` | Raw `LengthAwarePaginator` | `ApiResponse::paginated($logs)` |
| `AccessLogController`| `show` | `response()->json($accessLog->load(...))` | Bare Model object | `ApiResponse::success($accessLog)` |
| `StrangerSnapController`| `index`| `response()->json($snaps)` | Raw `LengthAwarePaginator` | `ApiResponse::paginated($snaps)` |
| `VisitorController`  | `index`, `listVisits` | `response()->json($query->paginate($perPage))` | Raw `LengthAwarePaginator` | `ApiResponse::paginated($paginated)` |
| `VisitorController`  | `show`, `showVisit` | `response()->json(['data' => $visitor])` | Partial envelope | `ApiResponse::success($visitor)` |
| `LeaveController`    | `listRequests` | `response()->json($query->paginate($perPage))` | Raw `LengthAwarePaginator` | `ApiResponse::paginated($paginated)` |
| `LeaveController`    | `listLeaveTypes`, `listBalances` | `response()->json($query->get())` | Flat collection | `ApiResponse::success($collection)` |
| `RegularizationController` | `index` | `response()->json($query->paginate($perPage))` | Raw `LengthAwarePaginator` | `ApiResponse::paginated($paginated)` |
| `ShiftController`    | `index` | `response()->json($query->get())` | Flat collection | `ApiResponse::success($collection)` |
| `OrganizationController` | All methods | `response()->json(['success' => true, 'data' => ...])` | **Already Compliant** | Maintain existing shape |
| `RoleController`     | All methods | `response()->json(['success' => true, 'data' => ...])` | **Already Compliant** | Maintain existing shape |
| `DashboardStatsController` | `index` | `response()->json($stats)` | Flat telemetry map | `ApiResponse::success($stats)` + root keys |

### 2.3 Unified API Envelope Design

To achieve uniformity without breaking existing frontend calls or PHPUnit test assertions, the API response envelope must satisfy dual-contract compatibility:

#### Single Resource Success Envelope (`ApiResponse::success`)
```json
{
  "success": true,
  "message": "Resource retrieved successfully.",
  "data": {
    "id": 1,
    "name": "Front Gate Camera",
    "device_id": "CAM-001"
  },
  "meta": {
    "timestamp": "2026-10-07T02:15:00Z",
    "version": "v1"
  }
}
```

#### Paginated Resource Envelope (`ApiResponse::paginated`)
```json
{
  "success": true,
  "message": null,
  "data": [
    { "id": 1, "first_name": "John", "last_name": "Wick" }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 5,
    "per_page": 15,
    "total": 75,
    "from": 1,
    "to": 15,
    "timestamp": "2026-10-07T02:15:00Z",
    "version": "v1"
  },
  "current_page": 1,
  "last_page": 5,
  "per_page": 15,
  "total": 75,
  "from": 1,
  "to": 15
}
```
*Crucial Backwards-Compatibility Rationale:*
1. Preserving root `current_page`, `last_page`, `per_page`, `total`, `from`, `to` alongside `meta` guarantees that any legacy frontend component or test checking `res.data.current_page` continues to work without runtime exceptions.
2. `data` contains the array of items, which matches both Laravel's native paginator JSON structure (`res.data.data`) and clean REST API specifications.

#### Error Envelope (`ApiResponse::error`)
```json
{
  "success": false,
  "message": "The given data was invalid.",
  "errors": {
    "employee_code": [
      "The employee code has already been taken."
    ]
  },
  "meta": {
    "timestamp": "2026-10-07T02:15:00Z",
    "version": "v1"
  }
}
```

### 2.4 Form Request Extraction Plan

Currently, hundreds of lines of inline validation clutter controllers. We will extract these into dedicated Form Request classes under `app/Http/Requests/`:

#### 1. Employee Domain (`app/Http/Requests/Employee/`)
- `StoreEmployeeRequest.php`: Validates `employee_code` (unique), `first_name`, `work_email` (unique), `personnel_id`, `department_id`, `shift_id`, `employment_type`, `employment_status`, `avatar`, `photo_base64`.
- `UpdateEmployeeRequest.php`: Validates partial updates with `sometimes|required`, unique exceptions for `employee_code` and `work_email` using route ID.
- `AssignShiftRequest.php`: Validates `shift_id` (exists:shifts,id), `effective_from` (date), `effective_to` (date|nullable).
- `ImportEmployeeCsvRequest.php`: Validates uploaded `file` (`required|file|mimes:csv,txt|max:5120`).

#### 2. Device Domain (`app/Http/Requests/Device/`)
- `StoreDeviceRequest.php`: Validates `device_id` (unique), `name`, `scheme` (`in:http,https`), `ip_address`, `port`, `username`, `password`, `device_type`, `device_role` (`in:entry,exit,bidirectional,visitor_kiosk`), `organization_id`, `location_id`.
- `UpdateDeviceRequest.php`: Same rules as store with `sometimes|required` and unique ignore.
- `BulkDeviceActionRequest.php`: Validates `device_ids` (`required|array|min:1`), `action` (`in:reboot,sync_mqtt,sync_time`).

#### 3. Shift Domain (`app/Http/Requests/Shift/`)
- `StoreShiftRequest.php`: Validates `name`, `code` (unique scoped to `organization_id`), `shift_start` & `shift_end` (time format), `grace_period_minutes`, `is_overnight`, overnight identical time validation.
- `UpdateShiftRequest.php`: Scoped unique ignore rule, partial updates.
- `AssignShiftScheduleRequest.php`: Validates `shift_id`, `employee_ids` (`array`), `start_date`, `end_date`.
- `BulkAssignShiftScheduleRequest.php`: Validates `department_ids`, `shift_id`, `effective_date`.

#### 4. Visitor Domain (`app/Http/Requests/Visitor/`)
- `StoreVisitorRequest.php`: Validates `first_name`, `last_name`, `company`, `email`, `phone`, `id_type`, `id_number`, `is_blocked`.
- `UpdateVisitorRequest.php`: Partial visitor profile updates.
- `PreRegisterVisitRequest.php`: Validates `visitor_id`, `host_employee_id`, `purpose`, `purpose_detail`, `expected_arrival`.
- `CheckInVisitRequest.php`: Validates `badge_number`, `nda_signed` (`boolean`), optional `photo_base64` or `photo` biometric capture.

#### 5. Leave & Regularization Domain (`app/Http/Requests/Leave/`)
- `StoreLeaveTypeRequest.php`: Validates `name`, `code` (unique), `max_days_per_year`, `is_paid`, `is_carry_forward`.
- `SubmitLeaveRequest.php`: Validates `employee_id`, `leave_type_id`, `start_date`, `end_date` (`after_or_equal:start_date`), `reason`, ownership check.
- `AllocateLeaveBalanceRequest.php`: Validates `employee_id`, `leave_type_id`, `year`, `allocated_days`.
- `StoreRegularizationRequest.php`: Validates `employee_id`, `date` (not future date), `requested_in`, `requested_out`, `reason`.

#### 6. Personnel Domain (`app/Http/Requests/Personnel/`)
- `StorePersonnelRequest.php`: Validates `name`, `person_type` (`in:0,1`), `customize_id` (`unique:personnel`), `id_card`, `tel_num`, `photo` (file, max 10MB, no SVG), `photo_base64`, `photo_url` (with SSRF check).
- `UpdatePersonnelRequest.php`: Same rules with ignore on update.

### 2.5 Scramble OpenAPI 3.1 Integration Architecture

1. **Package Dependency**:
   - Install `dedoc/scramble`: `composer require dedoc/scramble` (version `^0.13.47` verified compatible with Laravel Framework `13.26.1`).
2. **Configuration (`config/scramble.php`)**:
   ```php
   return [
       'api_path' => 'api',
       'api_domain' => null,
       'info' => [
           'version' => '1.0.0',
           'title' => 'Intelligent AI Camera Hub - REST API',
           'description' => 'Enterprise Access Control, Biometric Attendance, and Vision Telemetry API',
       ],
       'ui' => [
           'title' => 'AI Camera Hub API Docs',
           'theme' => 'light',
           'hide_try_it' => false,
           'logo' => '',
       ],
       'servers' => [
           'Local Development' => '/api',
       ],
   ];
   ```
3. **Security Scheme Registration (`app/Providers/AppServiceProvider.php`)**:
   ```php
   use Dedoc\Scramble\Scramble;
   use Dedoc\Scramble\Support\Generator\OpenApi;
   use Dedoc\Scramble\Support\Generator\SecurityScheme;
   use Illuminate\Support\Facades\Gate;

   public function boot(): void
   {
       Gate::define('viewApiDocs', fn ($user = null) => true);

       Scramble::extendOpenApi(function (OpenApi $openApi) {
           $openApi->secure(
               SecurityScheme::http('bearer', 'Sanctum API Token')
           );
       });
   }
   ```
4. **Interactive Documentation Endpoints**:
   - Web UI: `http://localhost:8000/docs/api` (renders modern Stoplight Elements UI)
   - OpenAPI 3.1 JSON Document: `http://localhost:8000/docs/api.json`

---

## 3. R8: Frontend Composable Architecture

### 3.1 Current Codebase Friction & Redundancy

Inspection of `resources/js/` components identified significant copy-pasted state management across multiple views:

```
resources/js/
├── views/
│   ├── LiveTelemetry.vue          --> Duplicates pagination, polling fallback, sound alert logic
│   ├── AccessLogsHistory.vue      --> Duplicates pagination, search debouncing, URL queries
│   ├── StrangerSnapsMonitor.vue   --> Duplicates pagination, date filters, file-to-base64 reader
│   ├── DeviceAlertsCenter.vue     --> Duplicates pagination, Echo channel listener & teardown
│   └── PersonnelManager.vue       --> Duplicates pagination, category filters, file-to-base64 reader
└── components/
    ├── employees/
    │   └── EmployeeFormModal.vue  --> Inline getUserMedia, canvas draw, square crop, video stream stop
    └── visitors/
        └── VisitorCheckInWizard.vue--> Lacks webcam capture, manual multi-step validation
```

### 3.2 Composable 1: `usePaginatedResource(endpoint, initialFilters)`

**Location:** `resources/js/composables/usePaginatedResource.js`  
**Purpose:** Standardizes REST query fetching, pagination navigation, search debouncing, and filter resets across any table or card list.

#### Interface & Return Contract:
```javascript
export function usePaginatedResource(endpoint, initialFilters = {}, options = {}) {
    // Reactive State
    const items = ref([]);
    const loading = ref(false);
    const error = ref(null);
    const filters = reactive({ ...initialFilters });
    const pagination = reactive({
        current_page: 1,
        last_page: 1,
        per_page: options.perPage || 15,
        total: 0,
        from: 0,
        to: 0,
    });

    // Core Methods
    const fetchPage = async (page = 1, silent = false) => { ... };
    const debouncedFetch = (delay = 300) => { ... };
    const goToPage = (page) => { ... };
    const nextPage = () => { ... };
    const prevPage = () => { ... };
    const resetFilters = () => { ... };
    const setPerPage = (count) => { ... };

    return {
        items,
        loading,
        error,
        filters,
        pagination,
        fetchPage,
        debouncedFetch,
        goToPage,
        nextPage,
        prevPage,
        resetFilters,
        setPerPage,
    };
}
```

#### Dual Response Parser Logic:
Handles both new envelope (`res.data.data` + `res.data.meta`) and legacy paginator structures (`res.data.data` + `res.data.current_page`):
```javascript
const responseData = res.data;
items.value = Array.isArray(responseData.data) 
    ? responseData.data 
    : (Array.isArray(responseData) ? responseData : []);

const meta = responseData.meta || responseData;
pagination.current_page = Number(meta.current_page) || page;
pagination.last_page = Number(meta.last_page) || 1;
pagination.per_page = Number(meta.per_page) || pagination.per_page;
pagination.total = Number(meta.total) || items.value.length;
pagination.from = Number(meta.from) || (items.value.length ? 1 : 0);
pagination.to = Number(meta.to) || items.value.length;
```

---

### 3.3 Composable 2: `useLiveTelemetryStream(channelName, eventHandlers)`

**Location:** `resources/js/composables/useLiveTelemetryStream.js`  
**Purpose:** Encapsulates Laravel Reverb WebSocket private channel subscription, event listener registration, real-time message deduplication, Web Audio synthesizer chime, and automated unmount cleanup.

#### Interface & Return Contract:
```javascript
export function useLiveTelemetryStream(channelName, eventHandlers = {}, options = {}) {
    const isConnected = ref(false);
    const lastEvent = ref(null);

    // Audio synthesizer helper (sine wave @ 880Hz / 440Hz, zero external mp3 file dependencies)
    const playAlertSound = (type = 'warning') => { ... };

    // Real-time deduplication helper for reactive arrays
    const deduplicateAndPrepend = (listRef, newItem, maxCapacity = 50) => { ... };

    // Lifecycle
    onMounted(() => {
        const channel = echo.private(channelName);
        for (const [eventName, handler] of Object.entries(eventHandlers)) {
            // Bind both dotted and bare event names to support Reverb/Pusher quirks
            const bare = eventName.startsWith('.') ? eventName.slice(1) : eventName;
            const dotted = '.' + bare;
            channel.listen(dotted, handler);
            channel.listen(bare, handler);
        }
    });

    onUnmounted(() => {
        echo.leave(channelName);
    });

    return {
        isConnected,
        lastEvent,
        playAlertSound,
        deduplicateAndPrepend,
    };
}
```

---

### 3.4 Composable 3: `useBiometricCapture(options)`

**Location:** `resources/js/composables/useBiometricCapture.js`  
**Purpose:** Reusable webcam stream management, video element binding, canvas square crop (1:1 ratio for facial recognition models), image compression, and file reading.

#### Interface & Return Contract:
```javascript
export function useBiometricCapture(options = {}) {
    const {
        targetWidth = 480,
        targetHeight = 480,
        mimeType = 'image/jpeg',
        quality = 0.9,
    } = options;

    const webcamActive = ref(false);
    const hasWebcamSupport = ref(!!navigator.mediaDevices?.getUserMedia);
    const videoRef = ref(null);
    const cameraError = ref('');
    const photoBase64 = ref('');
    const photoFile = ref(null);
    let mediaStream = null;

    // Methods
    const startWebcam = async () => {
        cameraError.value = '';
        try {
            mediaStream = await navigator.mediaDevices.getUserMedia({
                video: { width: 640, height: 480, facingMode: 'user' }
            });
            webcamActive.value = true;
            nextTick(() => {
                if (videoRef.value) {
                    videoRef.value.srcObject = mediaStream;
                }
            });
        } catch (err) {
            cameraError.value = 'Unable to access camera: ' + (err.message || 'Permission denied');
            webcamActive.value = false;
        }
    };

    const stopWebcam = () => {
        if (mediaStream) {
            mediaStream.getTracks().forEach(t => t.stop());
            mediaStream = null;
        }
        if (videoRef.value) {
            videoRef.value.srcObject = null;
        }
        webcamActive.value = false;
    };

    const captureFrame = () => {
        if (!videoRef.value) return null;
        const video = videoRef.value;
        const canvas = document.createElement('canvas');
        canvas.width = targetWidth;
        canvas.height = targetHeight;
        const ctx = canvas.getContext('2d');

        // Calculate center square crop to ensure facial recognition aspect ratio 1:1
        const size = Math.min(video.videoWidth || 640, video.videoHeight || 480);
        const startX = ((video.videoWidth || 640) - size) / 2;
        const startY = ((video.videoHeight || 480) - size) / 2;

        ctx.drawImage(video, startX, startY, size, size, 0, 0, targetWidth, targetHeight);
        const dataUrl = canvas.toDataURL(mimeType, quality);
        photoBase64.value = dataUrl;

        // Convert Data URL to File object for multipart form submissions
        const arr = dataUrl.split(',');
        const mime = arr[0].match(/:(.*?);/)[1];
        const bstr = atob(arr[1]);
        let n = bstr.length;
        const u8arr = new Uint8Array(n);
        while (n--) {
            u8arr[n] = bstr.charCodeAt(n);
        }
        photoFile.value = new File([u8arr], 'biometric_capture.jpg', { type: mime });

        stopWebcam();
        return dataUrl;
    };

    const handleFileUpload = (eventOrFile) => {
        const file = eventOrFile.target ? eventOrFile.target.files?.[0] : eventOrFile;
        if (!file) return;
        photoFile.value = file;
        const reader = new FileReader();
        reader.onload = (e) => {
            photoBase64.value = e.target.result;
        };
        reader.readAsDataURL(file);
    };

    const clearPhoto = () => {
        photoBase64.value = '';
        photoFile.value = null;
        stopWebcam();
    };

    onUnmounted(() => {
        stopWebcam();
    });

    return {
        webcamActive,
        hasWebcamSupport,
        videoRef,
        cameraError,
        photoBase64,
        photoFile,
        startWebcam,
        stopWebcam,
        captureFrame,
        handleFileUpload,
        clearPhoto,
    };
}
```

---

### 3.5 View Refactoring Matrix

| Target Component | Composables to Adopt | Refactoring Highlights |
| :--- | :--- | :--- |
| `LiveTelemetry.vue` | `usePaginatedResource`<br>`useLiveTelemetryStream` | - Replace 30 lines of local pagination state & page building with `usePaginatedResource('/api/access-logs', { verify_status: 'all' })`.<br>- Adopt `useLiveTelemetryStream('access-logs', ...)` for incoming verification events. |
| `AccessLogsHistory.vue` | `usePaginatedResource` | - Eliminate local `filters`, `pagination`, and `debouncedFetch` boilerplate.<br>- Bind inputs directly to `filters.search`, `filters.verify_status`. |
| `StrangerSnapsMonitor.vue` | `usePaginatedResource`<br>`useBiometricCapture` | - Use `usePaginatedResource('/api/stranger-snaps', { device_id: '', from: '', to: '' })`.<br>- Replace inline FileReader in `onEnrollFileSelected` with `useBiometricCapture()`. |
| `DeviceAlertsCenter.vue` | `usePaginatedResource`<br>`useLiveTelemetryStream` | - Manage alert pagination via `usePaginatedResource('/api/device-alerts')`.<br>- Unify dual Echo subscription (`DeviceAlertReceived`, `DeviceAlertUpdated`) into `useLiveTelemetryStream`. |
| `EmployeeFormModal.vue` | `useBiometricCapture` | - Remove manual `getUserMedia`, canvas manipulation, and track stopping (lines 455–489).<br>- Bind template video to `videoRef` and buttons to `startWebcam`, `captureFrame`, `stopWebcam`. |
| `VisitorCheckInWizard.vue` | `useBiometricCapture` | - Add biometric face capture UI to Step 3 ("Biometrics & Agreement").<br>- Enable webcam snapshot capture and photo upload for visitor badge generation. |

---

## 4. Implementation Manifest & Concrete File Changes

### 4.1 Backend Files to Create

```
app/
├── Http/
│   ├── Responses/
│   │   └── ApiResponse.php                        [NEW] Standard response helper / envelope builder
│   └── Requests/
│       ├── Employee/
│       │   ├── StoreEmployeeRequest.php           [NEW] Employee creation validation
│       │   ├── UpdateEmployeeRequest.php          [NEW] Employee update validation
│       │   ├── AssignShiftRequest.php             [NEW] Employee shift assignment validation
│       │   └── ImportEmployeeCsvRequest.php       [NEW] CSV import validation
│       ├── Device/
│       │   ├── StoreDeviceRequest.php             [NEW] Device creation validation
│       │   ├── UpdateDeviceRequest.php            [NEW] Device update validation
│       │   └── BulkDeviceActionRequest.php        [NEW] Fleet batch commands validation
│       ├── Shift/
│       │   ├── StoreShiftRequest.php              [NEW] Shift definition validation
│       │   ├── UpdateShiftRequest.php             [NEW] Shift modification validation
│       │   ├── AssignShiftScheduleRequest.php     [NEW] Shift schedule validation
│       │   └── BulkAssignShiftScheduleRequest.php [NEW] Department shift rotation validation
│       ├── Visitor/
│       │   ├── StoreVisitorRequest.php            [NEW] Visitor profile validation
│       │   ├── UpdateVisitorRequest.php           [NEW] Visitor profile update validation
│       │   ├── PreRegisterVisitRequest.php        [NEW] Visit pre-registration validation
│       │   └── CheckInVisitRequest.php            [NEW] Visitor check-in validation
│       ├── Leave/
│       │   ├── StoreLeaveTypeRequest.php          [NEW] Leave type validation
│       │   ├── UpdateLeaveTypeRequest.php         [NEW] Leave type update validation
│       │   ├── SubmitLeaveRequest.php             [NEW] Leave application validation
│       │   └── AllocateLeaveBalanceRequest.php    [NEW] Leave balance allocation validation
│       ├── Personnel/
│       │   ├── StorePersonnelRequest.php          [NEW] Biometric personnel validation
│       │   └── UpdatePersonnelRequest.php         [NEW] Biometric personnel update validation
│       ├── Organization/
│       │   ├── StoreOrganizationRequest.php       [NEW] Org hierarchy validation
│       │   ├── StoreLocationRequest.php           [NEW] Location validation
│       │   ├── StoreDepartmentRequest.php         [NEW] Department validation
│       │   └── StoreDesignationRequest.php        [NEW] Job title validation
│       └── Regularization/
│           └── StoreRegularizationRequest.php     [NEW] Attendance regularization validation
config/
└── scramble.php                                   [NEW] Scramble OpenAPI configuration
```

### 4.2 Backend Files to Modify

```
composer.json                                      [MODIFIED] Add "dedoc/scramble": "^0.13.47"
app/Providers/AppServiceProvider.php               [MODIFIED] Register Scramble OpenAPI Bearer security & docs gate
routes/api.php                                     [MODIFIED] Add route aliases for /api/v1/*
app/Http/Controllers/EmployeeController.php        [MODIFIED] Adopt Form Requests & ApiResponse
app/Http/Controllers/DeviceController.php          [MODIFIED] Adopt Form Requests & ApiResponse (preserve legacy keys)
app/Http/Controllers/VisitorController.php         [MODIFIED] Adopt Form Requests & ApiResponse
app/Http/Controllers/LeaveController.php           [MODIFIED] Adopt Form Requests & ApiResponse
app/Http/Controllers/ShiftController.php           [MODIFIED] Adopt Form Requests & ApiResponse
app/Http/Controllers/PersonnelController.php       [MODIFIED] Adopt Form Requests & ApiResponse
app/Http/Controllers/AccessLogController.php       [MODIFIED] Adopt ApiResponse::paginated / success
app/Http/Controllers/StrangerSnapController.php    [MODIFIED] Adopt ApiResponse::paginated / success
app/Http/Controllers/RegularizationController.php  [MODIFIED] Adopt Form Requests & ApiResponse
```

### 4.3 Frontend Files to Create

```
resources/js/composables/
├── usePaginatedResource.js                        [NEW] REST pagination, filter & debounce composable
├── useLiveTelemetryStream.js                      [NEW] Private Echo channel & sound alert composable
└── useBiometricCapture.js                         [NEW] Webcam stream, square crop & file reader composable
```

### 4.4 Frontend Files to Modify

```
resources/js/views/LiveTelemetry.vue               [MODIFIED] Adopt usePaginatedResource & useLiveTelemetryStream
resources/js/views/AccessLogsHistory.vue           [MODIFIED] Adopt usePaginatedResource
resources/js/views/StrangerSnapsMonitor.vue        [MODIFIED] Adopt usePaginatedResource & useBiometricCapture
resources/js/views/DeviceAlertsCenter.vue          [MODIFIED] Adopt usePaginatedResource & useLiveTelemetryStream
resources/js/views/PersonnelManager.vue            [MODIFIED] Adopt usePaginatedResource
resources/js/components/employees/EmployeeFormModal.vue [MODIFIED] Adopt useBiometricCapture
resources/js/components/visitors/VisitorCheckInWizard.vue [MODIFIED] Adopt useBiometricCapture in Step 3
```

---

## 5. Risk Assessment & Invalidation Conditions

1. **Risk 1: Edge Camera Firmware Regressions (High Severity)**
   - *Risk:* Edge cameras running X40Y embedded firmware parse HTTP POST responses from `/Subscribe/*` strictly according to manufacturer protocol. If `/Subscribe/*` returns `{ success: true, data: ... }`, camera verification acknowledgments fail, causing cameras to buffer un-flushed punches and disconnect.
   - *Mitigation:* Explicitly exempt `HttpWebhookController` from `ApiResponse` refactoring. Ensure tests in `HttpProtocolV113Test.php` pass without alteration.
2. **Risk 2: Breaking Existing PHPUnit Assertions on Legacy Keys (Medium Severity)**
   - *Risk:* Test cases like `DeviceManagementTest::test_can_get_device_detail` assert that `$response->assertJson(['id' => $device->id])` at the top level of the JSON response.
   - *Mitigation:* In `ApiResponse::success()`, when serializing a single model/array, inject the legacy attributes at the root level alongside `"data" => $data`, ensuring both `$response->json('data.id')` and `$response->json('id')` evaluate successfully.
3. **Risk 3: Webcam Stream Resource Leaks (Low Severity)**
   - *Risk:* Failure to explicitly stop media stream tracks causes the user's webcam LED indicator to remain permanently active in the browser.
   - *Mitigation:* `useBiometricCapture` implements `onUnmounted(() => stopWebcam())` and ensures `track.stop()` is called immediately upon capturing a frame or closing the modal.

---

## 6. Verification Plan & Test Commands

1. **PHPUnit Automated Test Suite**:
   ```bash
   php artisan test
   ```
   *Pass Criteria:* 358+ tests pass with 0 failures, validating all RBAC, employee CRUD, device sync, and protocol endpoints.
2. **Frontend Compilation Check**:
   ```bash
   npm run build
   ```
   *Pass Criteria:* Vite bundle builds in < 1 second with 0 syntax or template errors.
3. **OpenAPI Interactive Documentation Verification**:
   ```bash
   curl -s -o /dev/null -w "%{http_code}" http://localhost:8000/docs/api
   curl -s -o /dev/null -w "%{http_code}" http://localhost:8000/docs/api.json
   ```
   *Pass Criteria:* Returns HTTP 200 with valid OpenAPI 3.1 JSON schema.
4. **Composable Functionality Spot Check**:
   - Verify `usePaginatedResource` fetches page 1, reacts to debounced search, and transitions pages.
   - Verify `useBiometricCapture` activates webcam, draws a centered square 480x480 crop on canvas, and yields a valid JPEG Base64 data URL and File object.
