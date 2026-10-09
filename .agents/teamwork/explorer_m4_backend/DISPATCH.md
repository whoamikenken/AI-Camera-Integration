# DISPATCH DIRECTIVE — explorer_m4_backend

## Identity
- Archetype: teamwork_preview_explorer
- Working Directory: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m4_backend
- Parent Conversation ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb

## Mission
Investigate the backend architecture, database schema, background job queueing, and service layers for Milestone M4 (Bulk Workforce Operations & Fleet Provisioning Campaigns).

## Mandatory First Step
Read the following authoritative documents:
1. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md`
2. `/home/wsk-devops2/AI-Camera-Integration/system-evo.md` (Feature 5)
3. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md`
4. `/home/wsk-devops2/AI-Camera-Integration/TEST_READY.md`

## Exploration Areas
1. **Existing Controllers, Models, and Jobs**:
   - Inspect `app/Http/Controllers/DeviceController.php` and `app/Http/Controllers/PersonnelController.php`.
   - Inspect `app/Models/Device.php` and `app/Models/Personnel.php`.
   - Inspect `app/Jobs/SyncPersonnelJob.php` and how it uses `CameraGatewayInterface` / `CameraMqttService`.
   - Inspect `app/Http/Controllers/ShiftController.php` (`bulkAssign` pattern).
2. **Database Migration & Model Design**:
   - Design migration `database/migrations/xxxx_xx_xx_create_bulk_campaigns_table.php`.
   - Define columns: `id`, `user_id` (nullable), `campaign_type`, `total_items`, `processed_items`, `failed_items`, `status`, `payload` (json), `error_summary` (nullable text), `timestamps`.
   - Design `app/Models/BulkCampaign.php` with fillable properties, JSON casts, helper methods (`markProcessing`, `incrementProcessed`, `incrementFailed`, `markCompleted`, `markFailed`, `progressPercent`).
3. **Queue Jobs Design**:
   - Design `app/Jobs/BulkDeviceCampaignJob.php`: handles `reboot_fleet` and `update_mqtt_config` with chunking/rate-limiting across target devices.
   - Design `app/Jobs/BulkPersonnelSyncJob.php`: batches personnel records into `AddPersons` (up to 50 persons per packet) and handles bulk deletion (`DelPerson`/`DeletePersons`).
   - Check queue assignment: `camera-sync` Redis queue, error handling, progress updates to `BulkCampaign`.
4. **Endpoints & Routing**:
   - Design `POST /api/devices/bulk-reboot` and `POST /api/devices/bulk-sync-mqtt`.
   - Design `POST /api/personnel/bulk-sync` and `POST /api/personnel/bulk-delete`.
   - Design `GET /api/bulk-campaigns/{id}`.
   - Design validation rules (ensure empty array of IDs returns 422 Unprocessable Entity).
5. **Gateway & Mock Compatibility**:
   - Verify `FakeCameraGateway` and `CameraGatewayInterface` support `AddPersons`, `DelPerson`, `RebootDevice`, `UpMQTTconfig`.

## Output
Write your comprehensive backend blueprint to:
`/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m4_backend/handoff.md`

When complete, notify parent via `send_message` with summary and path.

## 2026-10-08T18:40:46Z
You are explorer_m4_backend.
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m4_backend
Your dispatch directive is at: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m4_backend/DISPATCH.md
Your parent is conversation ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb

MANDATORY FIRST STEP: Read the following documents:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m4_backend/DISPATCH.md
2. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
3. /home/wsk-devops2/AI-Camera-Integration/system-evo.md (Feature 5)
4. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md
5. /home/wsk-devops2/AI-Camera-Integration/TEST_READY.md

Investigate the backend architecture, models, migrations, jobs, and routes:
1. Inspect DeviceController, PersonnelController, Device, Personnel, SyncPersonnelJob, CameraMqttService, ShiftController::bulkAssign.
2. Design database migration create_bulk_campaigns_table and model BulkCampaign.
3. Design background jobs: BulkDeviceCampaignJob (fleet reboot & MQTT sync) and BulkPersonnelSyncJob (AddPersons chunking up to 50 persons per packet, bulk delete).
4. Design endpoints: POST /api/devices/bulk-reboot, POST /api/devices/bulk-sync-mqtt, POST /api/personnel/bulk-sync, POST /api/personnel/bulk-delete, GET /api/bulk-campaigns/{id}.
5. Design validation rules and edge cases (422 on empty selection, partial failures).

Write your report to /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m4_backend/handoff.md.
When finished, send a message to parent (ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb) via send_message.

