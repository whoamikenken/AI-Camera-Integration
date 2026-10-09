# Milestone M4 Specification Mining Report: Bulk Workforce Operations & Fleet Provisioning Campaigns (Features #20–#26)

## 1. Observation

### 1.1 Authoritative Specification Sources
1. **`ORIGINAL_REQUEST.md` (Header `## 2026-10-07T01:57:58Z`, Section R3 & Acceptance Criteria, lines 406–409, 440):**
   > "### R3. Bulk Workforce Operations & Fleet Provisioning Campaigns (Feature 5)
   > - Support bulk batch campaigns for device maintenance (e.g., bulk reboot, bulk MQTT configuration updates) and personnel provisioning (batch face enrollment and deletion).
   > - Provide batch tracking for campaign status and execution progress, with selection toolbars and batch actions in device and personnel management views.
   > - Acceptance Criteria: Bulk personnel and device campaign endpoints execute batch operations and report progress."

2. **`system-evo.md` (Feature 5: Bulk Workforce Operations & Fleet Provisioning Campaigns, lines 159–182):**
   > "- **Data Layer**: Migration `create_bulk_campaigns_table`: `id`, `user_id`, `campaign_type` (`sync_personnel`, `reboot_fleet`, `update_mqtt_config`, `shift_assignment`), `total_items`, `processed_items`, `failed_items`, `status`, `payload` (JSON), `timestamps`.
   > - **Logic / Services**:
   >   - `BulkDeviceCampaignJob`: Chunks device lists and batches MQTT downlink commands with rate-limiting to prevent broker congestion.
   >   - `BulkPersonnelSyncJob`: Batches personnel records into `AddPersons` commands (supporting up to 50 persons per MQTT downlink payload) rather than 1-by-1 `EditPerson` calls.
   > - **API / UI Surfaces**:
   >   - Routes: `POST /api/devices/bulk-reboot`, `POST /api/devices/bulk-sync-mqtt`, `POST /api/personnel/bulk-sync`, `POST /api/personnel/bulk-delete`.
   >   - UI: Multi-row checkbox selections and batch action toolbars in `DeviceManager.vue` and `PersonnelManager.vue`."

3. **`orchestrator_11/PROJECT.md` (Feature Inventory, Milestone M4, lines 36–42, 67):**
   > "| 20 | Bulk Campaigns Tracking Entity | Migration and `BulkCampaign` model tracking asynchronous batch tasks | M4 | Survey 1 |
   > | 21 | Fleet Bulk Reboot Endpoint & Job | Asynchronous batch reboot across selected cameras with rate limiting | M4 | Survey 1 |
   > | 22 | Fleet Bulk MQTT Sync Endpoint & Job | Asynchronous batch MQTT parameter updates (`UpMQTTconfig`) | M4 | Survey 1 |
   > | 23 | High-Throughput Bulk Personnel Sync | Batching face records into `AddPersons` (up to 50 persons per packet) | M4 | Survey 1 |
   > | 24 | Bulk Personnel Deletion Endpoint & Job | Batch deletion across personnel and edge cameras | M4 | Survey 1 |
   > | 25 | Bulk Campaign Progress API | `GET /api/bulk-campaigns/{id}` returning execution progress | M4 | Survey 1 |
   > | 26 | Fleet & Personnel Batch Toolbars | Multi-select checkboxes and batch actions in `DeviceManager.vue` & `PersonnelManager.vue` | M4 | Survey 1 |
   > | M4 | Bulk Workforce Operations & Fleet Campaigns | `bulk_campaigns`, batch reboot/MQTT sync, `AddPersons` batched sync, batch UI toolbars | M1, M2 | IN_PROGRESS |"

4. **`TEST_INFRA.md` & `TEST_READY.md` (Milestone 4 section):**
   > Features 20 through 26 mapped to isolated tests `test_f20` through `test_f26` in `Tier1FeatureCoverageTest.php`, boundary tests in `Tier2BoundaryTest.php`, cross-domain tests in `Tier3CrossFeatureTest.php`, and `test_scenario_8` in `Tier4RealWorldScenariosTest.php`.

5. **`docs/mqtt_protocol_v1.25.md` (Section 3.2: Batch Addition of Personnel via URI `AddPersons`, lines 231–275):**
   > MQTT downlink payload for batch addition:
   > `operator: "AddPersons"`, `PersonNum: N`, `DataBegin: "BeginFlag"`, `DataEnd: "EndFlag"`, `info: [ ... ]`.
   > Camera acknowledgment: `operator: "AddPersons-Ack"`, `info: { AddErrNum, AddSucNum, result: "ok" }`.

6. **`GEMINI.md` (Protocol Reference Mapping, lines 212–215):**
   > - `AddPersons`: `operator: "AddPersons"`, `PersonNum`, `Personinfo_0: {...}`
   > - `DelPerson` / `DeletePersons`: `operator: "DelPerson"` / `"DeletePersons"`, `customId: [...]`
   > - `RebootDevice`: `operator: "RebootDevice"`, `facesluiceId`
   > - `UpMQTTconfig`: `operator: "UpMQTTconfig"`, `StrangerUploadType`, `RecordUploadType`, `KeepAlive`

---

### 1.2 Inspection of Existing Automated Test Suites

#### 1.2.1 `tests/Feature/E2E/Tier1FeatureCoverageTest.php` (Lines 1393–1508)
- `test_f20_bulk_campaigns_entity_tracks_execution_progress`:
  - Enforces existence of table `bulk_campaigns` and model `App\Models\BulkCampaign`.
  - Asserts model creation with fields: `user_id`, `campaign_type` ('reboot_fleet'), `total_items` (10), `processed_items` (7), `failed_items` (1), `status` ('processing'), `payload` (`['device_ids' => [1..10]]`).
  - Asserts `assertDatabaseHas('bulk_campaigns', ['id' => $campaign->id, 'campaign_type' => 'reboot_fleet', 'status' => 'processing'])`.
- `test_f21_fleet_bulk_reboot_endpoint_dispatches_rate_limited_jobs`:
  - Enforces route `POST /api/devices/bulk-reboot` and table `bulk_campaigns`.
  - Request body: `['device_ids' => [$cam1->id, $cam2->id]]`.
  - Requires HTTP `202 Accepted`.
  - Requires JSON structure: `assertJsonStructure(['campaign_id'])`.
- `test_f22_fleet_bulk_mqtt_sync_endpoint_dispatches_parameter_updates`:
  - Enforces route `POST /api/devices/bulk-sync-mqtt` and table `bulk_campaigns`.
  - Request body: `['device_ids' => [$cam->id], 'mqtt_config' => ['KeepAlive' => 60, 'StrangerUploadType' => 1, 'RecordUploadType' => 1]]`.
  - Requires HTTP `202 Accepted`.
  - Requires JSON structure: `assertJsonStructure(['campaign_id'])`.
- `test_f23_high_throughput_bulk_personnel_sync_batches_up_to_50_persons`:
  - Enforces class `App\Jobs\BulkPersonnelSyncJob` and table `bulk_campaigns`.
  - Instantiates `new \App\Jobs\BulkPersonnelSyncJob([1, 2, 3])`.
- `test_f24_bulk_personnel_deletion_endpoint_removes_records_and_dispatches`:
  - Enforces route `POST /api/personnel/bulk-delete` and table `bulk_campaigns`.
  - Request body: `['personnel_ids' => [$p1->id, $p2->id]]`.
  - Requires HTTP `202 Accepted`.
  - Requires JSON structure: `assertJsonStructure(['campaign_id'])`.
- `test_f25_bulk_campaign_progress_api_returns_status_and_counters`:
  - Enforces route `GET /api/bulk-campaigns/1` (i.e. `/api/bulk-campaigns/{id}`) and table `bulk_campaigns`.
  - Creates campaign with `['id' => 1, 'campaign_type' => 'sync_personnel', 'total_items' => 50, 'processed_items' => 50, 'failed_items' => 0, 'status' => 'completed']`.
  - Requires HTTP `200 OK`.
  - Requires JSON fragment: `['status' => 'completed', 'total_items' => 50]`.
- `test_f26_fleet_and_personnel_batch_toolbars_exist_in_frontend`:
  - Enforces existence of files:
    - `resources/js/components/devices/DeviceManager.vue`
    - `resources/js/components/personnel/PersonnelManager.vue`
  - Asserts `assertFileExists(base_path('...'))` for both paths.

#### 1.2.2 `tests/Feature/E2E/Tier2BoundaryTest.php` (Lines 507–565)
- `test_boundary_bulk_personnel_sync_exact_50_person_chunking`:
  - 51 personnel items partitioned into chunks of max 50: `array_chunk($items, 50)` produces 2 chunks (count 50, count 1).
- `test_boundary_bulk_reboot_with_empty_devices_array_rejected_with_422`:
  - Posting empty `device_ids: []` to `POST /api/devices/bulk-reboot` must return HTTP `422 Unprocessable Entity`.
- `test_boundary_bulk_personnel_deletion_with_empty_array_rejected_with_422`:
  - Posting empty `personnel_ids: []` to `POST /api/personnel/bulk-delete` must return HTTP `422 Unprocessable Entity`.
- `test_boundary_bulk_campaign_progress_clamps_between_zero_and_one_hundred_percent`:
  - Progress calculation `round(($campaign->processed_items / $campaign->total_items) * 100)` must clamp between 0% and 100%, handling `total_items === 0` safely without division by zero.

#### 1.2.3 `tests/Feature/E2E/Tier3CrossFeatureTest.php` (Lines 280–317)
- `test_cross_bulk_fleet_campaign_correlates_individual_downlink_command_tickets`:
  - Bulk campaigns track batch progress while individual device downlink commands dispatch tickets (`DeviceCommand`) correlated by `messageId`.

#### 1.2.4 `tests/Feature/E2E/Tier4RealWorldScenariosTest.php` (Lines 280–315)
- `test_scenario_8_large_workforce_bulk_onboarding_campaign`:
  - 120 personnel batched into `[50, 50, 20]` packets across devices.
  - Lifecycle: `pending` -> `processing` -> `completed` with `processed_items = 120`.

---

## 2. Logic Chain

### 2.1 Database Entity Architecture (`bulk_campaigns`)
- **Observation:** `system-evo.md` (lines 169–171), `PROJECT.md` Feature 20, and `Tier1FeatureCoverageTest::test_f20` define the campaign entity.
- **Deduction:** The database table `bulk_campaigns` must track long-running batch operations initiated by administrators.
- **Specification:**
  - Table: `bulk_campaigns`
  - Columns:
    - `id` (bigIncrements, PK)
    - `user_id` (foreignId -> `users.id`, nullable, on delete set null)
    - `campaign_type` (varchar 64, NOT NULL): `'sync_personnel'`, `'reboot_fleet'`, `'update_mqtt_config'`, `'delete_personnel'`, `'shift_assignment'`
    - `total_items` (unsigned integer, default 0, NOT NULL)
    - `processed_items` (unsigned integer, default 0, NOT NULL)
    - `failed_items` (unsigned integer, default 0, NOT NULL)
    - `status` (varchar 32, default `'pending'`, NOT NULL): `'pending'`, `'processing'`, `'completed'`, `'failed'`, `'cancelled'`
    - `payload` (jsonb/json, nullable): stores request payload parameters
    - `error_summary` (jsonb/json, nullable): failure reasons and failed device/personnel IDs
    - `result_summary` (jsonb/json, nullable): execution metadata
    - `created_at`, `updated_at` (timestamps)
  - Indexes: `index(['campaign_type', 'status'])`, `index('created_at')`.

### 2.2 Eloquent Model Requirements (`App\Models\BulkCampaign`)
- **Observation:** `test_f20`, `test_f25`, and `test_boundary_bulk_campaign_progress_clamps_between_zero_and_one_hundred_percent` instantiate and query `BulkCampaign`.
- **Deduction:** The model must support array casting for `payload`, `error_summary`, and integer casting for item counters, plus a virtual attribute `progress_percent`.
- **Specification:**
  - `$fillable`: `['user_id', 'campaign_type', 'total_items', 'processed_items', 'failed_items', 'status', 'payload', 'error_summary', 'result_summary']`
  - `$casts`:
    - `'payload' => 'array'`
    - `'error_summary' => 'array'`
    - `'result_summary' => 'array'`
    - `'total_items' => 'integer'`
    - `'processed_items' => 'integer'`
    - `'failed_items' => 'integer'`
  - Accessor: `getProgressPercentAttribute(): int` computes `($this->total_items > 0) ? (int) min(100, max(0, round(($this->processed_items / $this->total_items) * 100))) : 0`.
  - Appends: `protected $appends = ['progress_percent'];`.
  - Relationship: `user(): BelongsTo` to `App\Models\User`.

### 2.3 API Route & Payload Specifications
- **Observation:** Tests require four `POST` mutation endpoints returning HTTP `202 Accepted` with `campaign_id` and one `GET` query endpoint returning HTTP `200 OK`.
- **Deductions & Contracts:**

#### 1. `POST /api/devices/bulk-reboot`
- Request:
  ```json
  {
    "device_ids": [1, 2, 3]
  }
  ```
- Validation Rules:
  - `device_ids`: `required|array|min:1`
  - `device_ids.*`: `required|integer|exists:devices,id`
- Error Response (Empty array `[]`): HTTP 422:
  ```json
  {
    "message": "The device ids field is required.",
    "errors": { "device_ids": ["The device ids field must have at least 1 items."] }
  }
  ```
- Success Response: HTTP 202:
  ```json
  {
    "success": true,
    "campaign_id": 101,
    "message": "Fleet reboot campaign initiated",
    "data": {
      "campaign_id": 101,
      "total_items": 3,
      "status": "pending"
    }
  }
  ```

#### 2. `POST /api/devices/bulk-sync-mqtt`
- Request:
  ```json
  {
    "device_ids": [1, 2],
    "mqtt_config": {
      "KeepAlive": 60,
      "StrangerUploadType": 1,
      "RecordUploadType": 1
    }
  }
  ```
- Validation Rules:
  - `device_ids`: `required|array|min:1`
  - `device_ids.*`: `required|integer|exists:devices,id`
  - `mqtt_config`: `required|array`
  - `mqtt_config.KeepAlive` / `KeepAliveInterval`: `nullable|integer`
  - `mqtt_config.StrangerUploadType`: `nullable|integer|in:0,1`
  - `mqtt_config.RecordUploadType`: `nullable|integer|in:0,1`
- Error Response: HTTP 422 on invalid/empty inputs.
- Success Response: HTTP 202:
  ```json
  {
    "success": true,
    "campaign_id": 102,
    "message": "Fleet MQTT sync campaign initiated",
    "data": {
      "campaign_id": 102,
      "total_items": 2,
      "status": "pending"
    }
  }
  ```

#### 3. `POST /api/personnel/bulk-sync`
- Request:
  ```json
  {
    "personnel_ids": [1, 2, 3, 4],
    "device_ids": [10, 11]
  }
  ```
  *(Note: `device_ids` is optional. When omitted, destination devices are resolved per personnel member via `AccessControlService::getAuthorizedDevicesForPersonnel()`.)*
- Validation Rules:
  - `personnel_ids`: `required|array|min:1`
  - `personnel_ids.*`: `required|integer|exists:personnel,id`
  - `device_ids`: `nullable|array`
  - `device_ids.*`: `integer|exists:devices,id`
- Error Response: HTTP 422 on empty `personnel_ids: []`.
- Success Response: HTTP 202:
  ```json
  {
    "success": true,
    "campaign_id": 103,
    "message": "Personnel bulk sync campaign initiated",
    "data": {
      "campaign_id": 103,
      "total_items": 4,
      "status": "pending"
    }
  }
  ```

#### 4. `POST /api/personnel/bulk-delete`
- Request:
  ```json
  {
    "personnel_ids": [1, 2]
  }
  ```
- Validation Rules:
  - `personnel_ids`: `required|array|min:1`
  - `personnel_ids.*`: `required|integer|exists:personnel,id`
- Error Response (Empty array `[]`): HTTP 422:
  ```json
  {
    "message": "The personnel ids field is required.",
    "errors": { "personnel_ids": ["The personnel ids field must have at least 1 items."] }
  }
  ```
- Success Response: HTTP 202:
  ```json
  {
    "success": true,
    "campaign_id": 104,
    "message": "Personnel bulk deletion campaign initiated",
    "data": {
      "campaign_id": 104,
      "total_items": 2,
      "status": "pending"
    }
  }
  ```

#### 5. `GET /api/bulk-campaigns/{id}`
- Response: HTTP 200:
  ```json
  {
    "id": 1,
    "campaign_type": "sync_personnel",
    "total_items": 50,
    "processed_items": 50,
    "failed_items": 0,
    "status": "completed",
    "progress_percent": 100,
    "payload": { ... },
    "error_summary": null,
    "result_summary": null,
    "created_at": "2026-10-09T02:00:00Z",
    "updated_at": "2026-10-09T02:01:00Z"
  }
  ```
  *(Must contain root keys `'status'` and `'total_items'` as asserted by `$response->assertJsonFragment(['status' => 'completed', 'total_items' => 50])`.)*

---

### 2.4 High-Throughput Chunking & Edge Protocol Payloads

#### 2.4.1 High-Throughput Face Enrollment (`AddPersons`)
- **Max Packet Size:** Exactly 50 persons per MQTT downlink payload (`docs/system-design/system_design.md:237`, `system-evo.md:173`, `Tier2BoundaryTest:507`).
- **Partitioning Algorithm:**
  ```php
  $chunks = array_chunk($personnelIds, 50);
  ```
- **Downlink Payload Construction:**
  - Topic: `mqtt/face/{DeviceID}`
  - Operator: `AddPersons`
  - Envelope Markers: `DataBegin: "BeginFlag"`, `DataEnd: "EndFlag"`
  - Counter: `PersonNum: count($chunk)`
  - Personnel Data Structure (Dual-Format Support):
    ```json
    {
      "messageId": "BATCH-ADD-67056E3A",
      "DataBegin": "BeginFlag",
      "operator": "AddPersons",
      "PersonNum": 2,
      "info": [
        {
          "customId": "10001",
          "CustomizeID": 10001,
          "name": "Jane Doe",
          "gender": 1,
          "personType": 0,
          "tempValid": 0,
          "validBegin": "2024-01-01 00:00:00",
          "validEnd": "2038-12-31 23:59:59",
          "effectNumber": 10000,
          "pic": "data:image/jpeg;base64,..."
        }
      ],
      "Total": 2,
      "Personinfo_0": { ... },
      "DataEnd": "EndFlag"
    }
    ```

#### 2.4.2 Edge Face Deletion (`DelPerson` / `DeletePersons`)
- For 1 person:
  ```json
  {
    "operator": "DelPerson",
    "info": {
      "facesluiceId": "{DeviceID}",
      "customId": "10001",
      "CustomizeID": [10001]
    }
  }
  ```
- For multiple persons (batch):
  ```json
  {
    "operator": "DeletePersons",
    "DataBegin": "BeginFlag",
    "info": {
      "facesluiceId": "{DeviceID}",
      "PersonNum": 2,
      "customId": ["10001", "10002"]
    },
    "DataEnd": "EndFlag"
  }
  ```

#### 2.4.3 Fleet Remote Reboot (`RebootDevice`)
- Downlink:
  ```json
  {
    "operator": "RebootDevice",
    "info": {
      "facesluiceId": "{DeviceID}",
      "IsRebootDevice": 1
    }
  }
  ```
- Rate Limiting: 50ms pause between successive MQTT device reboot dispatches (`usleep(50000)`) to protect broker stability.

#### 2.4.4 Fleet MQTT Parameter Update (`UpMQTTconfig`)
- Downlink:
  ```json
  {
    "operator": "UpMQTTconfig",
    "info": {
      "facesluiceId": "{DeviceID}",
      "MQEnable": 1,
      "MQAddr": "...",
      "MQPort": 1883,
      "MQTopic": "mqtt/face/{DeviceID}",
      "KeepAliveInterval": 60,
      "StrangerUploadType": 1,
      "RecordUploadType": 1,
      "OnlineTopic": "mqtt/face/basic",
      "HeartbeatTopic": "mqtt/face/heartbeat",
      "ResumefromBreakpoint": 1
    }
  }
  ```

---

### 2.5 Frontend Architectural Reconciliation: Component vs. View Layout
- **Observation:**
  - `tests/Feature/E2E/Tier1FeatureCoverageTest.php:1503-1508` specifically asserts:
    ```php
    $this->requireFile('resources/js/components/devices/DeviceManager.vue', 'Milestone 4');
    $this->requireFile('resources/js/components/personnel/PersonnelManager.vue', 'Milestone 4');
    $this->assertFileExists(base_path('resources/js/components/devices/DeviceManager.vue'));
    $this->assertFileExists(base_path('resources/js/components/personnel/PersonnelManager.vue'));
    ```
  - `resources/js/App.vue:585-590` currently imports:
    ```javascript
    const PersonnelManager = defineAsyncComponent(() => import("./views/PersonnelManager.vue"));
    const DeviceManager = defineAsyncComponent(() => import("./views/DeviceManager.vue"));
    ```
- **Deduction & Strategy:**
  - To maintain 100% compliance with both the E2E test file assertion (`test_f26`) and existing `App.vue` tabs:
    1. Place full-featured batch components at `resources/js/components/devices/DeviceManager.vue` and `resources/js/components/personnel/PersonnelManager.vue`.
    2. Have `resources/js/views/DeviceManager.vue` and `resources/js/views/PersonnelManager.vue` wrap or re-export those components (or keep them synchronized), guaranteeing both test passes and runtime dashboard rendering.
- **UI Batch Features Required:**
  - **`DeviceManager.vue`:**
    - "Select All" checkbox in header (`selectAll` boolean).
    - Checkbox on every camera card/row (`v-model="selectedDeviceIds"`).
    - Sticky/floating batch action toolbar visible whenever `selectedDeviceIds.length > 0`:
      - Selection counter: `"N devices selected"`.
      - **"Reboot Fleet"** button -> prompts `notify.confirm()` -> calls `POST /api/devices/bulk-reboot` -> displays campaign status modal/toast.
      - **"Batch MQTT Config"** button -> opens modal for KeepAlive/Upload types -> calls `POST /api/devices/bulk-sync-mqtt`.
      - **"Deselect All"** button.
  - **`PersonnelManager.vue`:**
    - "Select All" checkbox in table header `<th>`.
    - Row-level checkbox in table body `<td>` (`v-model="selectedPersonnelIds"`).
    - Sticky/floating batch action toolbar visible whenever `selectedPersonnelIds.length > 0`:
      - Selection counter: `"N personnel selected"`.
      - **"Sync to Cameras"** button -> calls `POST /api/personnel/bulk-sync` -> shows campaign progress.
      - **"Delete Selected"** button -> prompts destructive confirmation -> calls `POST /api/personnel/bulk-delete`.
      - **"Deselect All"** button.

---

## Features Discovered

| # | Category | Feature | Description | Inputs | Outputs | Error Behavior | Discovered Via |
|---|----------|---------|-------------|--------|---------|----------------|----------------|
| 1 | Data Layer | `bulk_campaigns` Table & `BulkCampaign` Model | Tracks batch lifecycle states, progress counters, payloads, and results | `user_id`, `campaign_type`, `total_items`, `processed_items`, `failed_items`, `status`, `payload`, `error_summary`, `result_summary` | Eloquent model instance with `progress_percent` computed attribute | Invalid status enum, missing required fields | `system-evo.md:170`, `Tier1FeatureCoverageTest:1393` |
| 2 | Fleet API | Bulk Device Reboot Endpoint | Dispatches asynchronous batch reboot across selected edge cameras | `POST /api/devices/bulk-reboot` with JSON `{ "device_ids": [1, 2, ...] }` | HTTP 202 Accepted with `{ "campaign_id": N, ... }` | 422 Unprocessable Entity if `device_ids` is empty `[]` or non-integer | `Tier1FeatureCoverageTest:1416`, `Tier2BoundaryTest:520` |
| 3 | Fleet API | Bulk Device MQTT Sync Endpoint | Dispatches batch MQTT parameter configuration updates across cameras | `POST /api/devices/bulk-sync-mqtt` with `{ "device_ids": [...], "mqtt_config": { ... } }` | HTTP 202 Accepted with `{ "campaign_id": N, ... }` | 422 Unprocessable Entity if `device_ids` empty or invalid config | `Tier1FeatureCoverageTest:1433` |
| 4 | Workforce API | High-Throughput Bulk Personnel Sync | Batches personnel face templates and schedules in max 50-person packets | `POST /api/personnel/bulk-sync` with `{ "personnel_ids": [...], "device_ids": [...]? }` | HTTP 202 Accepted with `{ "campaign_id": N, ... }` | 422 Unprocessable Entity if `personnel_ids` empty | `Tier1FeatureCoverageTest:1454`, `Tier2BoundaryTest:507` |
| 5 | Workforce API | Bulk Personnel Deletion Endpoint | Removes personnel records from database and dispatches edge face deletions | `POST /api/personnel/bulk-delete` with `{ "personnel_ids": [1, 2, ...] }` | HTTP 202 Accepted with `{ "campaign_id": N, ... }` | 422 Unprocessable Entity if `personnel_ids` is empty `[]` | `Tier1FeatureCoverageTest:1463`, `Tier2BoundaryTest:532` |
| 6 | Campaign API | Bulk Campaign Progress API | Inspects execution progress, item counters, status, and completion percentage | `GET /api/bulk-campaigns/{id}` | HTTP 200 OK with `{ "id": 1, "status": "completed", "total_items": 50, ... }` | 404 Not Found if campaign ID does not exist | `Tier1FeatureCoverageTest:1480`, `Tier2BoundaryTest:544` |
| 7 | UI Toolbar | Fleet Batch Action Toolbar | Multi-select checkboxes and batch actions in `DeviceManager.vue` | User click: select all, multi-card select, trigger reboot/MQTT sync | Batch request dispatch, confirmation dialog, real-time campaign progress tracking | Disables actions if 0 selected, displays error toast on network failure | `Tier1FeatureCoverageTest:1501`, `system-evo.md:180` |
| 8 | UI Toolbar | Personnel Batch Action Toolbar | Multi-select checkboxes and batch actions in `PersonnelManager.vue` | User click: select all, multi-row select, trigger bulk sync/delete | Batch request dispatch, confirmation modal, table refresh on completion | Disables actions if 0 selected, displays error toast on network failure | `Tier1FeatureCoverageTest:1501`, `system-evo.md:180` |
| 9 | Queue Worker | `BulkPersonnelSyncJob` | Asynchronous worker partitioning personnel into 50-item chunks and dispatching `AddPersons` | Array of `personnelIds`, optional `targetDeviceIds`, optional `campaignId` | Dispatched MQTT payloads to target devices, updated `BulkCampaign` counters | Retries 3 times on failure; marks campaign `failed` if fatal error | `Tier1FeatureCoverageTest:1456`, `Tier4RealWorldScenariosTest:280` |
| 10 | Queue Worker | `BulkDeviceRebootJob` | Asynchronous worker issuing rate-limited `RebootDevice` commands | `campaignId`, `deviceIds` | Dispatched MQTT `RebootDevice` payloads with 50ms rate-limit spacing | Logs individual device offline errors; updates `failed_items` | `system-evo.md:172` |
| 11 | Queue Worker | `BulkDeviceMqttSyncJob` | Asynchronous worker issuing `UpMQTTconfig` commands across devices | `campaignId`, `deviceIds`, `mqttConfig` | Dispatched MQTT `UpMQTTconfig` payloads; updates local `mqtt_topic` | Logs offline errors; updates `failed_items` | `system-evo.md:172` |
| 12 | Queue Worker | `BulkPersonnelDeleteJob` | Asynchronous worker issuing `DeletePersons` / `DelPerson` edge commands | `campaignId`, `personnelData` (custom IDs & target device mappings) | Dispatched MQTT `DeletePersons` payloads to purge hardware face database | Logs device failures; updates campaign counters | `system-evo.md:179` |
| 13 | Edge Downlink | `AddPersons` Downlink Payload | MQTT command packet containing up to 50 face profiles with photos/schedules | List of personnel profiles with base64/URL photos | Camera processes batch and replies with `AddPersons-Ack` | Camera returns error code 410 if busy with previous batch operation | `docs/mqtt_protocol_v1.25.md:231`, `GEMINI.md:213` |
| 14 | Edge Downlink | `DeletePersons` Downlink Payload | MQTT command packet purging multiple enrolled IDs from camera memory | List of string `customId`s | Camera removes face templates and returns `DeletePersons-Ack` | Camera returns error code if IDs not found or memory locked | `docs/mqtt_protocol_v1.25.md:320`, `GEMINI.md:214` |
| 15 | Testing Harness | `BulkCampaignFactory` | Eloquent factory for generating mock campaigns with expressive states | Factory states: `pending()`, `processing()`, `completed()`, `failed()`, `rebootFleet()` | `BulkCampaign` model instances in database | N/A | `TEST_INFRA.md:38` |

---

## Edge Cases

| # | Feature | Input | Observed Behavior |
|---|---------|-------|-------------------|
| 1 | Bulk Reboot | `POST /api/devices/bulk-reboot` with `device_ids: []` | **HTTP 422 Unprocessable Entity**: Validator rejects empty array via `min:1`. |
| 2 | Bulk Personnel Delete | `POST /api/personnel/bulk-delete` with `personnel_ids: []` | **HTTP 422 Unprocessable Entity**: Validator rejects empty array via `min:1`. |
| 3 | Bulk Personnel Sync | Exact 50-person boundary (e.g. 50 items) | Partitions into exactly **1 chunk** of 50 items. Single `AddPersons` downlink packet sent per target camera. |
| 4 | Bulk Personnel Sync | Exact 51-person boundary (e.g. 51 items) | Partitions into exactly **2 chunks**: chunk 0 has 50 items, chunk 1 has 1 item. Two distinct `AddPersons` packets sent. |
| 5 | Bulk Personnel Sync | Large workforce onboarding (e.g. 120 items) | Partitions into **3 chunks**: [50, 50, 20]. Three sequential packets dispatched per target camera. |
| 6 | Bulk Campaign Progress | Progress calculation when `total_items = 0` | Computes `progress_percent = 0%` safely without divide-by-zero exceptions. |
| 7 | Bulk Campaign Progress | Clamping when `processed_items >= total_items` | Computes `progress_percent = 100%`. Clamped strictly between 0 and 100. |
| 8 | Bulk Personnel Sync Target Devices | Omitted `device_ids` | Resolves target cameras dynamically per personnel member via `AccessControlService::getAuthorizedDevicesForPersonnel()`. If system has zero access groups, falls back to all active devices. |
| 9 | Bulk Reboot Rate Limiting | 100 cameras in fleet | Dispatches `RebootDevice` with `usleep(50000)` (50ms interval) between devices to prevent EMQX / Mosquitto packet drops. |
| 10 | Frontend Component Discovery | `test_f26` file lookup | Looks specifically for `resources/js/components/devices/DeviceManager.vue` and `resources/js/components/personnel/PersonnelManager.vue`. Files must exist in `components/` while remaining usable in `views/` or imported by `App.vue`. |
| 11 | Bulk Personnel Delete Invalidation | Personnel deleted from DB before edge purge | Custom IDs must be captured from `Personnel` models prior to DB deletion so edge commands receive the correct IDs. |
| 12 | Bulk MQTT Sync Parameters | Partial MQTT config payload (e.g. only `KeepAlive: 60`) | Merges partial config with device defaults (`MQEnable: 1`, `RecordUploadType: 1`, etc.) so camera retains broker connection. |

---

## 3. Caveats
1. **Component vs View Paths in Frontend**:
   `App.vue` currently dynamically imports `resources/js/views/DeviceManager.vue` and `resources/js/views/PersonnelManager.vue`. However, `Tier1FeatureCoverageTest::test_f26` strictly requires files at `resources/js/components/devices/DeviceManager.vue` and `resources/js/components/personnel/PersonnelManager.vue`. Both paths must be populated (either by co-locating components and re-exporting from views, or keeping both up to date) to satisfy both the automated test and the running SPA.
2. **Access Control Scoping on Bulk Sync**:
   When syncing personnel in bulk without an explicit `device_ids` array, the system must invoke `AccessControlService::getAuthorizedDevicesForPersonnel()` for each personnel record. Personnel belonging to different access groups must only be provisioned to their respective authorized cameras.
3. **Queue Configuration**:
   All bulk background jobs (`BulkPersonnelSyncJob`, `BulkDeviceRebootJob`, `BulkDeviceMqttSyncJob`, `BulkPersonnelDeleteJob`) must dispatch onto the `camera-sync` Redis queue, which is monitored by Horizon and queue workers.

---

## 4. Conclusion
All behavioral specifications, database entities, API endpoints, status codes, payload structures, 50-person chunking requirements, and UI batch toolbars for **Milestone M4 (Features #20 through #26)** have been completely mined and documented. The implementation team can immediately build:
1. Migration `create_bulk_campaigns_table` and `App\Models\BulkCampaign` (Feature 20).
2. `POST /api/devices/bulk-reboot` and `BulkDeviceRebootJob` (Feature 21).
3. `POST /api/devices/bulk-sync-mqtt` and `BulkDeviceMqttSyncJob` (Feature 22).
4. `POST /api/personnel/bulk-sync` and `BulkPersonnelSyncJob` with max 50-person chunking (Feature 23).
5. `POST /api/personnel/bulk-delete` and `BulkPersonnelDeleteJob` (Feature 24).
6. `GET /api/bulk-campaigns/{id}` with 0–100% progress clamping (Feature 25).
7. Multi-select toolbars in `DeviceManager.vue` and `PersonnelManager.vue` across both `components/` and `views/` (Feature 26).

---

## 5. Verification Method

### 5.1 Verification Commands
The implementation can be verified using the following commands:

```bash
# 1. Verify Milestone 4 Isolated Feature Tests (Features 20 - 26)
php artisan test --filter="test_f2[0-6]"

# 2. Verify Boundary & Corner Tests for Milestone 4 (Chunking & 422 validations)
php artisan test --filter="test_boundary_bulk"

# 3. Verify Scenario 8 Real-World Workforce Campaign
php artisan test --filter="test_scenario_8"

# 4. Verify Full E2E Test Suite Run
php artisan test --filter=E2E

# 5. Verify Frontend Build Toolchain
npm run build
```

### 5.2 Invalidation Conditions
- If `POST /api/devices/bulk-reboot` or `POST /api/personnel/bulk-delete` with empty arrays `[]` returns HTTP 200 or 202 instead of HTTP 422, boundary tests will fail.
- If `BulkPersonnelSyncJob` batches more than 50 persons in a single chunk, `test_boundary_bulk_personnel_sync_exact_50_person_chunking` will fail.
- If `GET /api/bulk-campaigns/{id}` does not return root keys `status` and `total_items`, `test_f25` will fail.
- If files `resources/js/components/devices/DeviceManager.vue` or `resources/js/components/personnel/PersonnelManager.vue` are missing, `test_f26` will fail.
