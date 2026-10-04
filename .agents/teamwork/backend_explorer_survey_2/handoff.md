# Backend Architecture Survey — Handoff Report

**Agent:** `teamwork_preview_explorer` (Backend Architecture Explorer)  
**Role:** Read-only Investigation & Backend Architecture Survey  
**Parent:** `ef2bd8f1-92a2-4f35-b75e-cfb5ce7f56ae` (parent orchestrator)  
**Working Directory:** `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/backend_explorer_survey_2`  
**Milestone:** Survey Phase Complete  

---

## 1. Observation

1. **PHP & Framework Versions:**
   - Command: `php -v` → `PHP 8.5.4 (cli) (built: Sep 2 2026 14:42:18) (NTS)`
   - Command: `php artisan --version` → `Laravel Framework 13.26.1`
   - File: `composer.json:8-16` defines:
     ```json
     "require": {
         "php": "^8.3",
         "laravel/framework": "^13.17",
         "laravel/horizon": "^5.48",
         "laravel/reverb": "^1.11",
         "laravel/tinker": "^3.0",
         "opcodesio/log-viewer": "^3.24",
         "php-mqtt/client": "^2.3"
     }
     ```
   - Notice that `laravel/sanctum`, export libraries (`openspout` or `maatwebsite/excel`, `barryvdh/laravel-dompdf`) are not yet installed in `composer.json`.

2. **Database & Testing Environments:**
   - File: `phpunit.xml:26-30` configures testing in SQLite in-memory:
     ```xml
     <env name="DB_CONNECTION" value="sqlite"/>
     <env name="DB_DATABASE" value=":memory:"/>
     <env name="QUEUE_CONNECTION" value="sync"/>
     <env name="CACHE_STORE" value="array"/>
     ```
   - File: `.env:20-26` configures development/production in PostgreSQL:
     ```env
     DB_CONNECTION=pgsql
     DB_HOST=127.0.0.1
     DB_PORT=5432
     DB_DATABASE=camera_hub
     DB_USERNAME=postgres
     DB_PASSWORD=secret
     ```
   - Command: `php artisan test` exited code 0 with output:
     `{"tool":"phpunit","result":"passed","tests":37,"passed":37,"assertions":119,"duration_ms":22452}`.
     All existing 37 tests in `tests/Feature/` pass cleanly.

3. **Existing Schema & Data Model:**
   - Migrations in `database/migrations/`:
     - `2026_08_22_000001_create_devices_table.php`: `devices` table with `device_id` (varchar 64 unique), `scheme`, `ip_address`, `port`, `device_type`, `is_active`, `last_heartbeat_at`.
     - `2026_08_22_000002_create_personnel_table.php`: `personnel` table with `customize_id` (bigint unique), `person_uuid` (uuid unique), `name`, `person_type` (0=whitelist, 1=blacklist), `temp_valid`, `valid_begin`, `valid_end`, `effect_number`, `photo_path`, `photo_base64`.
     - `2026_08_22_000003_create_access_logs_table.php`: `access_logs` table with `device_id`, `customize_id`, `verify_status`, `similarity`, `snap_pic_url`, `captured_at`.
     - `2026_08_22_000004_create_stranger_snaps_table.php`: `stranger_snaps` table with `device_id`, `snap_pic_url`, `captured_at`.
     - `2026_08_22_000005_create_sync_tasks_table.php` & `2026_08_22_010456_make_personnel_id_nullable_on_sync_tasks.php`: `sync_tasks` table.

4. **MQTT & Event Telemetry:**
   - File: `app/Console/Commands/MqttListenCommand.php:87-112`:
     Processes `VerifyPush`/`RecPush`, `StrSnapPush`/`SnapPush`, `HeartBeat`, `Online`/`Offline`.
     Inserts `AccessLog` (line 137), broadcasts `broadcast(new AccessLogReceived($log))` (line 155), and replies `PushAck` (line 160).
   - File: `app/Http/Controllers/HttpWebhookController.php:54-98`:
     Processes camera HTTP POST `/Subscribe/Verify`, creates `AccessLog`, broadcasts `AccessLogReceived`.
   - File: `app/Observers/PersonnelObserver.php:10-23`:
     Observes `Personnel` model events `created`, `updated`, `deleting` and dispatches `SyncPersonnelJob` on queue `camera-sync`.
   - File: `config/horizon.php:202`:
     Currently only configures `'queue' => ['default']`. `camera-sync` and future `attendance` queues are not yet listed in Horizon worker defaults.

5. **Route Configuration:**
   - File: `routes/api.php` contains 47 endpoints without any `auth:sanctum` middleware.
   - File: `bootstrap/app.php:16-18`:
     Configures CSRF exclusion for `action/*`. No API routes currently require authentication tokens. Camera webhooks (`POST /api/Subscribe/*`) are registered alongside public device endpoints.

---

## 2. Logic Chain

1. **Premise:** The system must transform into an Attendance and Visitor Management System across Phases 1–12 without breaking existing camera hardware interactions (Observation 3, 4).
2. **Hardware Invariant:** Edge cameras identify people by numeric `customize_id` and face templates provisioned via `Personnel` and `SyncPersonnelJob` (Observation 3, 4). When a person is recognized, the camera sends `VerifyPush` with `customId`, which is logged into `access_logs` and broadcast via `AccessLogReceived`.
3. **Bridge Pattern for Employees:** Therefore, the new `employees` domain must NOT replace the `personnel` table; instead, `employees.personnel_id` must maintain a 1-to-1 relationship with `personnel`. Creating an employee with a facial image programmatically creates/updates a `Personnel` record. By doing so, `PersonnelObserver` automatically triggers `SyncPersonnelJob` to sync the face to cameras without touching or rewriting existing device communication code.
4. **Bridge Pattern for Visitors:** For temporary visitor face access, creating a temporary `Personnel` record with `temp_valid = 1`, `valid_begin`, and `valid_end` on check-in pushes the face to cameras. On check-out, deleting the temporary `Personnel` triggers `SyncPersonnelJob('DELETE')`, cleanly revoking edge camera access.
5. **Decoupled Real-Time Attendance Pipeline:** Raw `access_logs` ingestion must remain ultra-fast and non-blocking in `MqttListenCommand` to prevent MQTT message queuing or camera timeout drops. Therefore, an asynchronous Event Listener listening to `AccessLogReceived` will queue a `ProcessAttendancePunchJob`. In testing (where `QUEUE_CONNECTION=sync`), punches and daily records evaluate immediately; in production, workers process attendance without impacting the MQTT loop.
6. **Device Direction & Roles:** Devices currently have no direction attributes. By adding nullable `organization_id`, `location_id`, and `device_role` (`entry`, `exit`, `bidirectional`, `visitor_kiosk`) to `devices`, existing devices default safely to `'bidirectional'`, avoiding breaking changes while providing the attendance engine with directional context for punch pairing.
7. **Cross-Database Compatibility:** Automated tests run against SQLite `:memory:` with foreign keys enabled, while production runs on PostgreSQL 16 (Observation 2). Therefore, all new migrations (21 tables/alterations) must avoid raw PostgreSQL-only constructs and use standard Laravel Schema Blueprint methods.

---

## 3. Caveats

1. **Queue Configuration in Production:** `config/horizon.php` defaults to queue `['default']`. During implementation, `camera-sync` and `attendance` must be added to Horizon configuration, or Supervisord must run separate workers for `--queue=camera-sync,attendance,default`.
2. **Camera Webhook Auth Exemption:** When `auth:sanctum` is applied across `/api/*`, the camera HTTP webhook routes (`/api/Subscribe/heartbeat`, `/api/Subscribe/Verify`, `/api/Subscribe/Snap`) and local mock hardware routes (`/action/*`) MUST remain exempt from authentication, as cameras do not transmit Bearer tokens.
3. **Hardware Offline During Visitor Checkout:** If an edge camera is powered off or unreachable during visitor check-out, the `SyncPersonnelJob` will mark the task `FAILED` in `sync_tasks`. A reconciliation job or camera audit can clean up stale faces upon reconnection.

---

## 4. Conclusion

1. The existing foundation is sound, modern (Laravel 13.26.1, PHP 8.5.4), and passes all 37 test cases cleanly.
2. The transformation can be accomplished with **zero regressions** to camera telemetry and device synchronization by employing the **Bridge & Layering Pattern**:
   - `employees` and `visitors` layer on top of `personnel`.
   - `attendance_punches` and `attendance_records` layer on top of `access_logs` and `devices`.
   - RBAC and Organization multi-tenancy layer across `users` and `devices` via nullable foreign keys.
3. A complete 21-step migration sequence, service boundary design, and test matrix have been documented in:
   `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/backend_explorer_survey_2/survey_backend_report.md`.

---

## 5. Verification Method

To verify these findings and confirm baseline stability:

1. **Verify Baseline Tests:**
   ```bash
   cd /home/wsk-devops2/AI-Camera-Integration
   php artisan test
   ```
   *Expected Result:* 37 passed tests, 119 assertions, 0 failures.

2. **Inspect Survey Report:**
   ```bash
   view_file /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/backend_explorer_survey_2/survey_backend_report.md
   ```
   *Expected Content:* 8 comprehensive sections covering versions, invariants, domain design, schema migrations, service boundaries, and test harness plans.

3. **Invalidation Conditions:**
   - Any migration modifying existing column names or non-nullable constraints in `devices`, `personnel`, `access_logs`, or `sync_tasks`.
   - Any test failure in existing test files (`HttpProtocolV113Test.php`, `PersonnelSyncTest.php`, `DeviceManagementTest.php`).
   - Blocking calls in `MqttListenCommand` or `HttpWebhookController` that slow down camera event ingestion.
