# Implementation Tasks & Status

## Intelligent Vision Edge & Telemetry Hub

### 1. Database & Models
- [x] Create PostgreSQL 16 migrations:
  - `devices` (device_id, name, ip_address, port, credentials, type, mqtt_topic, status, timestamps)
  - `personnel` (customize_id, person_uuid, name, person_type, gender, id_card, tel_num, address, schedules, photo_path, photo_base64)
  - `access_logs` (device_id, customize_id, person_name, verify_status, verify_type, similarity, snap_pic_url, scene_pic_url, target_pos, mask, captured_at)
  - `stranger_snaps` (device_id, snap_id, snap_pic_url, scene_pic_url, target_pos, alarm_action, captured_at)
  - `sync_tasks` (device_id, personnel_id, action, status, attempts, error_message)
- [x] Create Eloquent Models with UUIDs, casts, relationships, and online status accessors:
  - `App\Models\Device`
  - `App\Models\Personnel`
  - `App\Models\AccessLog`
  - `App\Models\StrangerSnap`
  - `App\Models\SyncTask`

### 2. Services, Jobs & Event Broadcasting
- [x] `App\Services\ImageStorageService`: Base64 decoding to JPEG files and uploaded image processing.
- [x] `App\Services\CameraHttpService`: Direct LAN camera REST communication (`EditPersonNew`, `DeletePerson`, `DeleteAllPerson`, `SearchPersonList`, `SetMQTTParam`, `RebootDevice`, `GetSysParam`).
- [x] `App\Jobs\SyncPersonnelJob`: Queued job executing on `camera-sync` Redis queue.
- [x] `App\Observers\PersonnelObserver`: Automatic queue dispatch on created/updated/deleted personnel.
- [x] Broadcast Events:
  - `App\Events\AccessLogReceived` (channel: `access-logs`)
  - `App\Events\StrangerSnapReceived` (channel: `stranger-snaps`)
  - `App\Events\DeviceStatusUpdated` (channel: `device-status`)

### 3. MQTT Ingestion Daemon
- [x] `App\Console\Commands\MqttListenCommand`: Long-running daemon listening to `mqtt/face/#`:
  - `VerifyPush` / `RecPush` decoding and database ingestion.
  - `SnapPush` / `StrSnapPush` stranger alert ingestion.
  - `HeartBeat` device keepalive updates.
  - `Online` / `Offline` status updates.
  - Automatic `PushAck` response for continuous transmission.

### 4. REST API Endpoints (`routes/api.php`)
- [x] `DeviceController`: Fleet list, create, edit, delete, ping test, reboot, and push MQTT settings.
- [x] `PersonnelController`: Face library CRUD with multipart photo upload, base64 conversion, and manual sync trigger.
- [x] `AccessLogController`: Paginated logs with multi-field search and filters.
- [x] `StrangerSnapController`: Paginated stranger captures list.
- [x] `SyncTaskController`: Outbox queue inspection and retry dispatch.
- [x] `DashboardStatsController`: Aggregated telemetry, device health, and sync queue statistics.

### 5. Frontend SPA (Vue 3 + Vite + Tailwind CSS + Pinia)
- [x] `resources/js/echo.js`: Laravel Echo Reverb WebSocket connector.
- [x] `resources/js/stores/cameraStore.js`: Pinia state management with live stream buffers and audio alarm synthesis.
- [x] `resources/js/views/LiveTelemetry.vue`: Real-time streaming monitoring feed with snapshot modals, similarity meters, and audio alert toggle.
- [x] `resources/js/views/PersonnelManager.vue`: Employee face enrollment modal with live image preview and validity schedules.
- [x] `resources/js/views/DeviceManager.vue`: Camera fleet status cards with ping tests, reboot actions, and MQTT setup modal.
- [x] `resources/js/views/AccessLogsHistory.vue`: Filterable historical access audit table.
- [x] `resources/js/views/SyncTasksMonitor.vue`: Redis outbox queue monitor and retry actions.
- [x] `resources/js/App.vue`: Main layout shell with real-time KPI metrics and dark mode UI.
