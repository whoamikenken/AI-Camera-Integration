# Handoff Report — Frontend Composables & Reactive Architecture (Milestone M6)

**Agent:** `explorer_m6_frontend`  
**Milestone:** M6 (Features #38, #39, #40, #41)  
**Parent Conversation ID:** `340b2ee2-86ac-4ca7-9f71-8c1542c65adb`  
**Target Working Directory:** `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m6_frontend`  
**Date:** 2026-10-09  

---

## 1. Observation

### 1.1 Existing Codebase Architecture & File System Structure
1. **Composables Directory Absence**:
   - Examination of `/home/wsk-devops2/AI-Camera-Integration/resources/js` revealed that `resources/js/composables/` does not currently exist.
   - Files present in `resources/js/` include `App.vue`, `app.js`, `echo.js`, `api/client.js`, `api/bulkCampaigns.js`, `stores/*`, `utils/*`, `views/*`, and `components/*`.
2. **Test Expectations in `Tier1FeatureCoverageTest.php`**:
   - Inspection of `tests/Feature/E2E/Tier1FeatureCoverageTest.php:1710-1733`:
     ```php
     public function test_f38_universal_paginated_resource_composable_exists(): void
     {
         $this->requireFile('resources/js/composables/usePaginatedResource.js', 'Milestone 6');
         $this->assertFileExists(base_path('resources/js/composables/usePaginatedResource.js'));
     }

     public function test_f39_live_telemetry_stream_composable_exists(): void
     {
         $this->requireFile('resources/js/composables/useLiveTelemetryStream.js', 'Milestone 6');
         $this->assertFileExists(base_path('resources/js/composables/useLiveTelemetryStream.js'));
     }

     public function test_f40_biometric_capture_composable_exists(): void
     {
         $this->requireFile('resources/js/composables/useBiometricCapture.js', 'Milestone 6');
         $this->assertFileExists(base_path('resources/js/composables/useBiometricCapture.js'));
     }

     public function test_f41_frontend_views_refactored_to_consume_composables(): void
     {
         $this->requireFile('resources/js/components/telemetry/LiveTelemetry.vue', 'Milestone 6');
         $this->assertFileExists(base_path('resources/js/components/telemetry/LiveTelemetry.vue'));
     }
     ```
   - Running `php artisan test --filter="test_f3[89]|test_f4[01]"` skips with code 0 because the required files do not yet exist on disk.
   - Note critical path finding: `test_f41` checks specifically for `resources/js/components/telemetry/LiveTelemetry.vue` (currently `LiveTelemetry.vue` only exists under `resources/js/views/LiveTelemetry.vue`).
3. **Current Duplicated Pagination Patterns**:
   - `resources/js/views/AccessLogsHistory.vue:170-226`:
     Defines duplicated reactive refs: `logs`, `loading`, `filters`, `pagination = { current_page, last_page, total, per_page, from, to }`, an inline `debounceTimer` (300ms) for search, and manual bounds-checked `changePage()`.
   - `resources/js/views/StrangerSnapsMonitor.vue:603-699`:
     Defines duplicated `snaps`, `loading`, `filters = { deviceId, from, to }`, `pagination`, `fetchSnaps(page = 1)`, and `goToPage(page)`.
   - `resources/js/views/DeviceAlertsCenter.vue:550-657`:
     Defines duplicated `alerts`, `loading`, `selectedCategory`, `selectedSeverity`, `selectedStatus`, `selectedDevice`, `pagination`, and `fetchAlerts(page = 1)`.
   - `resources/js/views/PersonnelManager.vue:446-520`:
     Defines duplicated `records`, `loading`, `search`, `personTypeFilter`, `validityFilter`, `pagination`, and `fetchPersonnel(page = 1)`.
   - `resources/js/views/SyncTasksMonitor.vue:100-145`:
     Defines duplicated `tasks`, `loading`, `statusFilter`, and `fetchTasks()`.
   - `resources/js/views/LiveTelemetry.vue:254-340`:
     Defines duplicated `logs`, `loading`, `statusFilter`, `perPage`, `currentPage`, `totalLogs`, `lastPage`, and `fetchLogs()`.
4. **Current WebSocket Echo & Audio Telemetry Patterns**:
   - `resources/js/echo.js:1-53`:
     Exports a pre-configured `Echo` client (`default echo`) configured with `reverb` broadcaster, host/port resolution, and `/broadcasting/auth` authorizer attaching Bearer token and CSRF tokens.
   - `routes/channels.php:9-52`:
     Defines authorized channels: `access-logs`, `device-alerts`, `alerts`, `stranger-snaps`, `device-status`, `attendance`, `visitors`, `personnel`, `sync-tasks`, `device-commands`.
   - `resources/js/stores/cameraStore.js:423-446`:
     Contains a Web Audio API audio synthesizer (`AudioContext`, `OscillatorNode`, `GainNode`) playing a 440Hz -> 220Hz sawtooth wave tone on rejected verifications and critical alerts.
   - `resources/js/views/DeviceAlertsCenter.vue:731-745`:
     Directly binds `echo.private('device-alerts')` for `.DeviceAlertReceived` and `.DeviceAlertUpdated`, and manually tears it down in `onUnmounted`.
5. **Current Biometric Face Photo Capture Patterns**:
   - `resources/js/components/employees/EmployeeFormModal.vue:455-489`:
     ```javascript
     async function openWebcam() {
       mediaStream = await navigator.mediaDevices.getUserMedia({ video: { width: 640, height: 480 } });
       webcamActive.value = true;
       setTimeout(() => { if (videoRef.value) videoRef.value.srcObject = mediaStream; }, 100);
     }
     function captureFrame() {
       if (!videoRef.value) return;
       const canvas = document.createElement('canvas');
       canvas.width = 480;
       canvas.height = 480;
       const ctx = canvas.getContext('2d');
       ctx.drawImage(videoRef.value, 80, 0, 480, 480, 0, 0, 480, 480);
       const dataUrl = canvas.toDataURL('image/jpeg', 0.9);
       form.photo_base64 = dataUrl;
       stopWebcam();
     }
     ```
     - Hardcodes cropping `ctx.drawImage(videoRef.value, 80, 0, 480, 480, 0, 0, 480, 480)`, assuming exact 640x480 webcam input. On 16:9 or portrait webcams, this distorts or clips faces.
     - Lacks validation for minimum dimensions (e.g. 200x200 px) required for biometric face recognition on edge cameras.
6. **Frontend Build Toolchain**:
   - `npm run build` executed successfully with exit code 0 (`built in 824ms`, 141 modules transformed, zero bundling errors).

---

## 2. Logic Chain

1. **Composable Directory Creation**:
   - `Tier1FeatureCoverageTest` requires files in `resources/js/composables/`.
   - Creating `resources/js/composables/` is the standard location for reusable Vue 3 Composition API utilities.
2. **Designing `usePaginatedResource.js` (Feature 38)**:
   - Backend endpoints in Laravel return varied structures:
     - Standard Paginator: `{ data: [...], current_page: 1, last_page: 5, total: 68, per_page: 15, from: 1, to: 15 }`
     - Milestone 6 `ApiResponse`: `{ success: true, data: { data: [...], current_page: 1, ... }, meta: {...} }`
     - API Resource Collection: `{ data: [...], meta: { current_page: 1, last_page: 5, total: 68 } }`
     - Plain flat array: `[...]` or `{ data: [...] }`
   - Therefore, `usePaginatedResource` must provide a **Universal Response Normalizer** that inspects `res.data` and correctly extracts `items` and `pagination` metrics regardless of envelope structure.
   - `usePaginatedResource` must accept either a URL string (using `apiClient`) or a custom fetch function `(params) => Promise<any>`.
   - `searchQuery` must have a built-in 300ms debounce that resets to page 1 upon entry, eliminating boilerplate in views.
   - `mutate` method is required to support real-time WebSocket prepending/updating without re-triggering full HTTP queries.
3. **Designing `useLiveTelemetryStream.js` (Feature 39)**:
   - Views need real-time data from Reverb channels (`access-logs`, `device-alerts`, `stranger-snaps`, `attendance`, etc.).
   - Creating a composable wrapping `echo.private()` / `echo.channel()` centralizes:
     - Connection status tracking (`isConnected`).
     - Event buffering with configurable buffer size (`maxBufferSize = 50`) and deduplication (`id` or `captured_at + device_id`).
     - Audio chime synthesizer using Web Audio API:
       - No external `.mp3` assets required (zero 404 risk, zero asset latency).
       - Chime tone: Harmonic sine wave (C5: 523.25 Hz -> E5: 659.25 Hz) for allowed verifications.
       - Alert tone: Sawtooth pulse (440 Hz -> 220 Hz) for denied punches, unauthorized strangers, or security hazards.
     - Automatic subscription on mount and automatic cleanup (`stopListening` / `leave`) on `onUnmounted`.
4. **Designing `useBiometricCapture.js` (Feature 40)**:
   - Edge cameras (X40Y) require square 1:1 facial templates with high clarity.
   - Dynamic 1:1 square center crop:
     ```javascript
     const vw = video.videoWidth || 640;
     const vh = video.videoHeight || 480;
     const size = Math.min(vw, vh);
     const sx = (vw - size) / 2;
     const sy = (vh - size) / 2;
     ctx.drawImage(video, sx, sy, size, size, 0, 0, targetSize, targetSize);
     ```
   - Validates minimum dimensions (e.g. 200x200 px).
   - Generates both Data URL (`data:image/jpeg;base64,...`) and raw Base64 string.
   - Provides `processImageFile(file)` for file upload center-cropping and validation.
   - Stream lifecycle management (`startCamera`, `stopCamera`, `isStreaming`, `hasSupport`, `error`).
5. **Targeting View Refactoring (Feature 41)**:
   - Primary views to refactor:
     1. `resources/js/views/LiveTelemetry.vue` (consumes `useLiveTelemetryStream` + `usePaginatedResource`).
     2. `resources/js/components/telemetry/LiveTelemetry.vue` (created to satisfy `test_f41`).
     3. `resources/js/views/AccessLogsHistory.vue` (consumes `usePaginatedResource`).
     4. `resources/js/views/StrangerSnapsMonitor.vue` (consumes `usePaginatedResource` + `useLiveTelemetryStream`).
     5. `resources/js/views/DeviceAlertsCenter.vue` (consumes `usePaginatedResource` + `useLiveTelemetryStream`).
     6. `resources/js/views/SyncTasksMonitor.vue` (consumes `usePaginatedResource` + `useLiveTelemetryStream`).
     7. `resources/js/views/PersonnelManager.vue` (consumes `usePaginatedResource`).
     8. `resources/js/components/employees/EmployeeFormModal.vue` (consumes `useBiometricCapture`).
   - Clean compilation through `npm run build` must be preserved throughout.

---

## 3. Caveats

1. **Web Audio Autoplay Restrictions**:
   Browsers require a user gesture (click/key) before `AudioContext` can transition from `'suspended'` to `'running'`. The composable must handle `if (ctx.state === 'suspended') ctx.resume();` gracefully.
2. **Hardware Webcam Permissions in Headless Test Environments**:
   `navigator.mediaDevices` may be undefined or throw `NotAllowedError` in automated headless browser environments (CI/CD). `useBiometricCapture` must handle missing media devices gracefully via `hasSupport` without throwing runtime crashes.
3. **Echo Channel Authentication Guards**:
   All private channels require authenticated Sanctum/web sessions. `useLiveTelemetryStream` uses `echo.private` by default and falls back cleanly if unauthenticated.
4. **Existing Views Custom Filters**:
   Different views use different query parameter names (e.g., `verify_status` in `AccessLogsHistory` vs `alert_type` in `DeviceAlertsCenter`). `usePaginatedResource` must allow arbitrary reactive `filters` objects to be passed and merged into query parameters.

---

## 4. Conclusion

The frontend composable architecture is fully investigated and ready for implementation. The 3 composables and view refactoring can be implemented cleanly with zero regressions to the existing Vite build:

### Proposed Implementation Specifications

#### A. `resources/js/composables/usePaginatedResource.js`
```javascript
import { ref, reactive, computed, watch, onMounted } from 'vue';
import apiClient from '../api/client';

export function usePaginatedResource(endpointOrFetchFn, options = {}) {
    const {
        initialPage = 1,
        initialPerPage = 15,
        initialFilters = {},
        initialSort = { field: '', direction: 'desc' },
        debounceMs = 300,
        immediate = true,
        transform = null,
    } = options;

    const items = ref([]);
    const loading = ref(false);
    const error = ref(null);
    const currentPage = ref(initialPage);
    const perPage = ref(initialPerPage);
    const searchQuery = ref('');
    const filters = reactive({ ...initialFilters });
    const sort = reactive({ ...initialSort });

    const rawPagination = ref({
        current_page: initialPage,
        last_page: 1,
        per_page: initialPerPage,
        total: 0,
        from: 0,
        to: 0,
    });

    const pagination = computed(() => rawPagination.value);

    // Universal response parser
    function parseResponse(res) {
        if (!res) return { items: [], meta: {} };
        const raw = res.data !== undefined ? res.data : res;

        // 1. Wrapped ApiResponse: { success: true, data: { data: [...], current_page: ... } }
        if (raw && raw.data && typeof raw.data === 'object' && Array.isArray(raw.data.data)) {
            const pageData = raw.data;
            return {
                items: pageData.data,
                meta: {
                    current_page: pageData.current_page || 1,
                    last_page: pageData.last_page || 1,
                    per_page: pageData.per_page || perPage.value,
                    total: pageData.total || pageData.data.length,
                    from: pageData.from || 1,
                    to: pageData.to || pageData.data.length,
                }
            };
        }

        // 2. Standard Laravel Paginator: { data: [...], current_page: ..., last_page: ..., total: ... }
        if (raw && Array.isArray(raw.data) && (raw.current_page !== undefined || raw.total !== undefined)) {
            return {
                items: raw.data,
                meta: {
                    current_page: raw.current_page || 1,
                    last_page: raw.last_page || 1,
                    per_page: raw.per_page || perPage.value,
                    total: raw.total !== undefined ? raw.total : raw.data.length,
                    from: raw.from || 1,
                    to: raw.to || raw.data.length,
                }
            };
        }

        // 3. API Resource Collection with meta: { data: [...], meta: { current_page: ..., ... } }
        if (raw && Array.isArray(raw.data) && raw.meta) {
            return {
                items: raw.data,
                meta: {
                    current_page: raw.meta.current_page || 1,
                    last_page: raw.meta.last_page || 1,
                    per_page: raw.meta.per_page || perPage.value,
                    total: raw.meta.total !== undefined ? raw.meta.total : raw.data.length,
                    from: raw.meta.from || 1,
                    to: raw.meta.to || raw.data.length,
                }
            };
        }

        // 4. Flat Array: [ ... ] or { data: [ ... ] }
        const flatList = Array.isArray(raw) ? raw : (Array.isArray(raw.data) ? raw.data : []);
        return {
            items: flatList,
            meta: {
                current_page: 1,
                last_page: 1,
                per_page: flatList.length || perPage.value,
                total: flatList.length,
                from: flatList.length > 0 ? 1 : 0,
                to: flatList.length,
            }
        };
    }

    async function fetch(page = currentPage.value, silent = false) {
        if (!silent) loading.value = true;
        error.value = null;

        try {
            const params = {
                page,
                per_page: perPage.value,
                ...filters,
            };

            if (searchQuery.value) {
                params.search = searchQuery.value;
            }
            if (sort.field) {
                params.sort_by = sort.field;
                params.sort_direction = sort.direction;
            }

            let res;
            if (typeof endpointOrFetchFn === 'function') {
                res = await endpointOrFetchFn(params);
            } else {
                res = await apiClient.get(endpointOrFetchFn, { params });
            }

            const parsed = parseResponse(res);
            let resultItems = parsed.items;
            if (typeof transform === 'function') {
                resultItems = resultItems.map(transform);
            }

            items.value = resultItems;
            currentPage.value = parsed.meta.current_page;
            rawPagination.value = parsed.meta;
        } catch (err) {
            error.value = err;
            console.error('usePaginatedResource fetch error:', err);
        } finally {
            if (!silent) loading.value = false;
        }
    }

    function changePage(page) {
        if (page >= 1 && page <= rawPagination.value.last_page) {
            currentPage.value = page;
            fetch(page);
        }
    }

    function nextPage() {
        if (currentPage.value < rawPagination.value.last_page) {
            changePage(currentPage.value + 1);
        }
    }

    function prevPage() {
        if (currentPage.value > 1) {
            changePage(currentPage.value - 1);
        }
    }

    function refresh(silent = false) {
        return fetch(currentPage.value, silent);
    }

    function setFilter(key, value) {
        filters[key] = value;
        currentPage.value = 1;
        fetch(1);
    }

    function resetFilters() {
        Object.keys(filters).forEach(k => {
            filters[k] = initialFilters[k] !== undefined ? initialFilters[k] : '';
        });
        searchQuery.value = '';
        currentPage.value = 1;
        fetch(1);
    }

    function mutate(mutator) {
        if (typeof mutator === 'function') {
            items.value = mutator(items.value);
        } else if (Array.isArray(mutator)) {
            items.value = mutator;
        }
    }

    let searchTimer = null;
    watch(searchQuery, () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            currentPage.value = 1;
            fetch(1);
        }, debounceMs);
    });

    if (immediate) {
        onMounted(() => fetch(currentPage.value));
    }

    return {
        items,
        loading,
        error,
        page: currentPage,
        currentPage,
        perPage,
        searchQuery,
        filters,
        sort,
        pagination,
        fetch,
        changePage,
        nextPage,
        prevPage,
        refresh,
        setFilter,
        resetFilters,
        mutate,
    };
}
```

#### B. `resources/js/composables/useLiveTelemetryStream.js`
```javascript
import { ref, computed, onMounted, onUnmounted } from 'vue';
import echo from '../echo';

let sharedAudioCtx = null;

function getAudioContext() {
    if (typeof window === 'undefined') return null;
    if (!sharedAudioCtx) {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (AudioCtx) sharedAudioCtx = new AudioCtx();
    }
    if (sharedAudioCtx && sharedAudioCtx.state === 'suspended') {
        sharedAudioCtx.resume().catch(() => {});
    }
    return sharedAudioCtx;
}

export function playSynthesizedChime(type = 'chime') {
    try {
        const ctx = getAudioContext();
        if (!ctx) return;

        const now = ctx.currentTime;
        const gain = ctx.createGain();
        gain.connect(ctx.destination);

        if (type === 'chime' || type === 'success' || type === 'allowed') {
            // Harmonic dual chime (C5 -> E5)
            const osc1 = ctx.createOscillator();
            const osc2 = ctx.createOscillator();
            osc1.type = 'sine';
            osc2.type = 'sine';
            osc1.frequency.setValueAtTime(523.25, now);
            osc2.frequency.setValueAtTime(659.25, now + 0.1);

            gain.gain.setValueAtTime(0.15, now);
            gain.gain.exponentialRampToValueAtTime(0.001, now + 0.4);

            osc1.connect(gain);
            osc2.connect(gain);
            osc1.start(now);
            osc1.stop(now + 0.2);
            osc2.start(now + 0.1);
            osc2.stop(now + 0.4);
        } else {
            // Dissonant warning pulse (440Hz -> 220Hz sawtooth)
            const osc = ctx.createOscillator();
            osc.type = 'sawtooth';
            osc.frequency.setValueAtTime(440, now);
            osc.frequency.exponentialRampToValueAtTime(220, now + 0.3);

            gain.gain.setValueAtTime(0.2, now);
            gain.gain.linearRampToValueAtTime(0.01, now + 0.3);

            osc.connect(gain);
            osc.start(now);
            osc.stop(now + 0.3);
        }
    } catch (err) {
        // Audio policy ignore
    }
}

export function useLiveTelemetryStream(channelName, options = {}) {
    const {
        isPrivate = true,
        events = {},
        soundEnabled: initialSound = false,
        maxBufferSize = 50,
        deduplicateBy = null,
        autoConnect = true,
    } = options;

    const stream = ref([]);
    const lastEvent = ref(null);
    const isConnected = ref(false);
    const soundEnabled = ref(initialSound);

    let channelInstance = null;

    function defaultKey(item) {
        if (!item) return Math.random().toString();
        return item.id ? String(item.id) : `${item.captured_at}_${item.device_id}`;
    }

    const keyResolver = typeof deduplicateBy === 'function' ? deduplicateBy : defaultKey;

    function handleIncomingPacket(eventName, rawData) {
        let payload = rawData;
        if (payload && typeof payload.data === 'object' && !Array.isArray(payload.data)) {
            payload = payload.data;
        }

        lastEvent.value = payload;

        // Deduplication & unshift buffer
        const key = keyResolver(payload);
        const index = stream.value.findIndex(item => keyResolver(item) === key);

        if (index !== -1) {
            stream.value[index] = { ...stream.value[index], ...payload };
            stream.value = [...stream.value];
        } else {
            stream.value = [payload, ...stream.value];
            if (stream.value.length > maxBufferSize) {
                stream.value.pop();
            }
        }

        // Optional Audio Alert Chime
        if (soundEnabled.value) {
            const isDenied = Number(payload.verify_status) === 2 || payload.severity === 'CRITICAL';
            playSynthesizedChime(isDenied ? 'alert' : 'chime');
        }

        // Execute custom registered event handler if present
        if (events[eventName] && typeof events[eventName] === 'function') {
            events[eventName](payload);
        }
    }

    function subscribe() {
        if (!channelName || channelInstance) return;

        channelInstance = isPrivate ? echo.private(channelName) : echo.channel(channelName);
        isConnected.value = true;

        // Register default event patterns based on channel
        const eventNames = Object.keys(events);
        if (eventNames.length === 0) {
            if (channelName === 'access-logs') eventNames.push('AccessLogReceived');
            if (channelName === 'stranger-snaps') eventNames.push('StrangerSnapReceived');
            if (channelName === 'device-alerts') eventNames.push('DeviceAlertReceived', 'DeviceAlertUpdated');
            if (channelName === 'attendance') eventNames.push('AttendancePunchReceived');
            if (channelName === 'sync-tasks') eventNames.push('SyncTaskUpdated');
        }

        eventNames.forEach(evt => {
            const dotEvt = evt.startsWith('.') ? evt : `.${evt}`;
            const plainEvt = evt.startsWith('.') ? evt.substring(1) : evt;

            channelInstance
                .listen(dotEvt, (data) => handleIncomingPacket(plainEvt, data))
                .listen(plainEvt, (data) => handleIncomingPacket(plainEvt, data));
        });
    }

    function unsubscribe() {
        if (!channelInstance) return;
        echo.leave(channelName);
        channelInstance = null;
        isConnected.value = false;
    }

    function playChime(type = 'chime') {
        playSynthesizedChime(type);
    }

    function clearBuffer() {
        stream.value = [];
        lastEvent.value = null;
    }

    if (autoConnect) {
        onMounted(() => subscribe());
        onUnmounted(() => unsubscribe());
    }

    return {
        stream,
        lastEvent,
        isConnected,
        soundEnabled,
        eventCount: computed(() => stream.value.length),
        subscribe,
        unsubscribe,
        playChime,
        clearBuffer,
    };
}
```

#### C. `resources/js/composables/useBiometricCapture.js`
```javascript
import { ref, computed, onUnmounted } from 'vue';

export function useBiometricCapture(options = {}) {
    const {
        targetDimension = 480,
        minDimension = 200,
        quality = 0.9,
    } = options;

    const isStreaming = ref(false);
    const error = ref(null);
    const capturedImage = ref('');
    const rawBase64 = computed(() => {
        if (!capturedImage.value) return '';
        return capturedImage.value.replace(/^data:image\/[a-zA-Z]+;base64,/, '');
    });

    const hasSupport = computed(() => {
        return typeof navigator !== 'undefined' && !!navigator.mediaDevices?.getUserMedia;
    });

    let currentStream = null;

    async function startCamera(videoElement, constraints = { width: 1280, height: 720, facingMode: 'user' }) {
        error.value = null;
        if (!hasSupport.value) {
            error.value = 'Webcam not supported by your browser.';
            return false;
        }

        try {
            stopCamera();
            currentStream = await navigator.mediaDevices.getUserMedia({
                video: constraints,
            });

            if (videoElement) {
                const el = videoElement.value !== undefined ? videoElement.value : videoElement;
                if (el) {
                    el.srcObject = currentStream;
                    await el.play().catch(() => {});
                }
            }

            isStreaming.value = true;
            return true;
        } catch (err) {
            console.warn('startCamera failed:', err);
            error.value = err.name === 'NotAllowedError'
                ? 'Camera access denied by user.'
                : 'Could not initialize camera device.';
            isStreaming.value = false;
            return false;
        }
    }

    function stopCamera() {
        if (currentStream) {
            currentStream.getTracks().forEach(track => track.stop());
            currentStream = null;
        }
        isStreaming.value = false;
    }

    function captureFrame(videoElement, targetSize = targetDimension) {
        error.value = null;
        const el = videoElement?.value !== undefined ? videoElement.value : videoElement;
        if (!el) {
            error.value = 'Video element not available for capture.';
            return null;
        }

        const vw = el.videoWidth || 640;
        const vh = el.videoHeight || 480;

        if (vw < minDimension || vh < minDimension) {
            error.value = `Video resolution (${vw}x${vh}) is below minimum required ${minDimension}x${minDimension}px.`;
            return null;
        }

        // Calculate 1:1 square center crop coordinates
        const cropSize = Math.min(vw, vh);
        const sx = (vw - cropSize) / 2;
        const sy = (vh - cropSize) / 2;

        const canvas = document.createElement('canvas');
        canvas.width = targetSize;
        canvas.height = targetSize;
        const ctx = canvas.getContext('2d');

        ctx.drawImage(el, sx, sy, cropSize, cropSize, 0, 0, targetSize, targetSize);

        const dataUrl = canvas.toDataURL('image/jpeg', quality);
        capturedImage.value = dataUrl;
        return dataUrl;
    }

    async function processImageFile(file, targetSize = targetDimension) {
        error.value = null;
        return new Promise((resolve, reject) => {
            if (!file || !file.type.startsWith('image/')) {
                error.value = 'Invalid file type. Please upload an image.';
                return reject(new Error(error.value));
            }

            const reader = new FileReader();
            reader.onload = (e) => {
                const img = new Image();
                img.onload = () => {
                    const iw = img.naturalWidth;
                    const ih = img.naturalHeight;

                    if (iw < minDimension || ih < minDimension) {
                        error.value = `Image resolution (${iw}x${ih}) is below minimum required ${minDimension}x${minDimension}px.`;
                        return reject(new Error(error.value));
                    }

                    const cropSize = Math.min(iw, ih);
                    const sx = (iw - cropSize) / 2;
                    const sy = (ih - cropSize) / 2;

                    const canvas = document.createElement('canvas');
                    canvas.width = targetSize;
                    canvas.height = targetSize;
                    const ctx = canvas.getContext('2d');

                    ctx.drawImage(img, sx, sy, cropSize, cropSize, 0, 0, targetSize, targetSize);
                    const dataUrl = canvas.toDataURL('image/jpeg', quality);
                    capturedImage.value = dataUrl;
                    resolve(dataUrl);
                };
                img.onerror = () => {
                    error.value = 'Failed to load image for processing.';
                    reject(new Error(error.value));
                };
                img.src = e.target.result;
            };
            reader.onerror = () => {
                error.value = 'Failed to read file.';
                reject(new Error(error.value));
            };
            reader.readAsDataURL(file);
        });
    }

    function clear() {
        capturedImage.value = '';
        error.value = null;
    }

    onUnmounted(() => {
        stopCamera();
    });

    return {
        isStreaming,
        hasSupport,
        error,
        capturedImage,
        rawBase64,
        startCamera,
        stopCamera,
        captureFrame,
        processImageFile,
        clear,
    };
}
```

#### D. Component Proxy `resources/js/components/telemetry/LiveTelemetry.vue`
```vue
<template>
  <LiveTelemetryView />
</template>

<script setup>
import LiveTelemetryView from '../../views/LiveTelemetry.vue';
</script>
```

---

## 5. Verification Method

To independently verify this investigation and the upcoming implementation:

1. **Check Files Presence**:
   Verify the creation of:
   - `resources/js/composables/usePaginatedResource.js`
   - `resources/js/composables/useLiveTelemetryStream.js`
   - `resources/js/composables/useBiometricCapture.js`
   - `resources/js/components/telemetry/LiveTelemetry.vue`

2. **Automated PHPUnit Tests**:
   Execute:
   ```bash
   php artisan test --filter=test_f38
   php artisan test --filter=test_f39
   php artisan test --filter=test_f40
   php artisan test --filter=test_f41
   ```
   All 4 tests will transition from `SKIPPED` to `PASSED`.

3. **Frontend Compilation Check**:
   Execute:
   ```bash
   npm run build
   ```
   Must succeed with exit code 0 and zero bundling or template errors.

4. **Invalidation Conditions**:
   - Any modification to `usePaginatedResource` that fails to parse flat arrays or wrapped `ApiResponse` structures.
   - Any audio synthesis relying on external file paths that 404.
   - Hardcoded webcam crop coordinates that break on 16:9 or mobile portrait cameras.
