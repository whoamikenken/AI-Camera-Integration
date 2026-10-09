# DISPATCH DIRECTIVE — worker_m4_1_rep

## Identity
- Archetype: teamwork_preview_worker
- Working Directory: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m4_1_rep
- Parent Conversation ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb

## MANDATORY INTEGRITY WARNING
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A teamwork_preview_auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

## Context
You are replacing `worker_m4_1`, which was interrupted by a network timeout after creating `database/migrations/2026_10_08_000002_create_bulk_campaigns_table.php`. Resume directly from that point.

## Authoritative Inputs
Read the following documents before writing code:
1. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (header `## 2026-10-07T01:57:58Z`)
2. `/home/wsk-devops2/AI-Camera-Integration/system-evo.md` (Feature 5)
3. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md` (Milestone M4)
4. Explorer Handoff Reports:
   - `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/spec_miner_m4_1/handoff.md` (Specifications & Contracts)
   - `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m4_backend/handoff.md` (Backend Blueprints)
   - `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/explorer_m4_frontend/handoff.md` (Frontend Blueprints)

## Implementation Tasks

### 1. Database Migration & Eloquent Model
- Note: `database/migrations/2026_10_08_000002_create_bulk_campaigns_table.php` is already created. Inspect and verify it matches the blueprint.
- Create model `app/Models/BulkCampaign.php`:
  - Fillable attributes, casts (`payload => array`, counters as integers).
  - Virtual accessor `progress_percent` with `progressPercent()` helper:
    `($this->total_items > 0) ? (int) min(100, max(0, round(($this->processed_items / $this->total_items) * 100))) : 0`.
  - Mutation helpers: `markProcessing()`, `incrementProcessed(int $count = 1)`, `incrementFailed(int $count = 1, ?string $error = null)`, `markCompleted(?string $finalStatus = null)`, `markFailed(string $error)`.
  - Relationship `user(): BelongsTo`.
- Create factory `database/factories/BulkCampaignFactory.php`.

### 2. Camera Gateway & Service Enhancements
- Add `addPersons(Device $device, array $personnelItems): array` to `app/Contracts/CameraGatewayInterface.php`.
- Implement `addPersons` in `app/Gateways/MqttCameraGateway.php`:
  - Formats payload with `Total` / `PersonNum` and `Personinfo_{idx}` entries. Calls `publishCommand($device, 'AddPersons', $info)`.
- Implement `addPersons` in `app/Gateways/FakeCameraGateway.php`:
  - Formats payload and calls `recordDispatch('publishCommand', $device, 'AddPersons', $info)`.
- Implement `addPersons` in `app/Gateways/HttpCameraGateway.php`.
- Add convenience method `addPersons(Device $device, array $personnelItems): array` in `app/Services/CameraMqttService.php`.

### 3. Background Jobs
- Create `app/Jobs/BulkDeviceCampaignJob.php`:
  - On queue `'camera-sync'`.
  - Handles `reboot_fleet` and `update_mqtt_config`.
  - Reads `device_ids` from `BulkCampaign` payload.
  - Updates campaign status to `'processing'`.
  - Loops devices, issues commands via `CameraMqttService`, increments processed or failed count, applies 50ms pause if `!app()->runningUnitTests()`.
  - Calls `$campaign->markCompleted()`.
- Create `app/Jobs/BulkPersonnelSyncJob.php`:
  - On queue `'camera-sync'`.
  - Constructor: `public function __construct(public array $personnelIds = [], public ?int $campaignId = null, public string $action = 'sync', public ?int $targetDeviceId = null)`.
  - Handles `sync` action:
    - Resolves authorized devices per personnel via `AccessControlService::getAuthorizedDevicesForPersonnel($person)`.
    - Maps `device_id => [personnel...]`.
    - For each device, chunks into at most 50 persons (`array_chunk($persons, 50)`).
    - Dispatches `addPersons` to edge camera. Creates `SyncTask` record.
    - Increments campaign processed/failed counters.
  - Handles `delete` action:
    - Resolves authorized devices per personnel and extracts custom IDs.
    - Dispatches `deletePerson($device, $customIds)` to edge cameras.
    - Deletes personnel from database (`Personnel::whereIn('id', $this->personnelIds)->delete()`).
    - Increments campaign processed counter and calls `$campaign->markCompleted()`.

### 4. Controllers & Routes
- Create `app/Http/Controllers/BulkCampaignController.php`:
  - `index(Request $request)`: paginated listing with optional filters.
  - `show(int $id)`: returns JSON representation with `status`, `total_items`, `processed_items`, `failed_items`, `progress_percent`.
- In `app/Http/Controllers/DeviceController.php`:
  - Add `bulkReboot(Request $request)`: validates `device_ids` (`required|array|min:1`, `device_ids.* => integer|exists:devices,id`), creates `BulkCampaign`, dispatches `BulkDeviceCampaignJob`, returns `202 Accepted` with `['campaign_id' => $campaign->id]`.
  - Add `bulkSyncMqtt(Request $request)`: validates `device_ids` (`required|array|min:1`) and `mqtt_config` (`required|array`), creates `BulkCampaign`, dispatches `BulkDeviceCampaignJob`, returns `202 Accepted` with `['campaign_id' => $campaign->id]`.
- In `app/Http/Controllers/PersonnelController.php`:
  - Add `bulkSync(Request $request)`: validates `personnel_ids` (`required|array|min:1`), creates `BulkCampaign`, dispatches `BulkPersonnelSyncJob`, returns `202 Accepted` with `['campaign_id' => $campaign->id]`.
  - Add `bulkDelete(Request $request)`: validates `personnel_ids` (`required|array|min:1`), creates `BulkCampaign`, dispatches `BulkPersonnelSyncJob` with action `'delete'`, returns `202 Accepted` with `['campaign_id' => $campaign->id]`.
- In `routes/api.php`:
  - Add routes:
    - `POST /api/devices/bulk-reboot` -> `DeviceController@bulkReboot`
    - `POST /api/devices/bulk-sync-mqtt` -> `DeviceController@bulkSyncMqtt`
    - `POST /api/personnel/bulk-sync` -> `PersonnelController@bulkSync`
    - `POST /api/personnel/bulk-delete` -> `PersonnelController@bulkDelete`
    - `GET /api/bulk-campaigns` -> `BulkCampaignController@index`
    - `GET /api/bulk-campaigns/{id}` -> `BulkCampaignController@show`

### 5. Frontend Implementation
- Create `resources/js/api/bulkCampaigns.js` with methods for all 5 endpoints.
- Create `resources/js/stores/bulkCampaignStore.js` with Pinia store tracking campaigns and polling `GET /api/bulk-campaigns/{id}` every 1200ms.
- Create `resources/js/components/BulkCampaignProgressModal.vue` with semantic progress bar (`role="progressbar"`), percent calculations, item counters, accessible close button.
- Update `resources/js/views/DeviceManager.vue`:
  - Multi-select checkboxes on device cards.
  - "Select All" master checkbox and clear selection button.
  - Sticky/floating batch action toolbar with "Reboot Fleet" and "Sync MQTT Config" buttons.
  - Bulk MQTT configuration dialog modal (WCAG 2.1 AA dialog).
  - Integration with `BulkCampaignProgressModal`.
- Update `resources/js/views/PersonnelManager.vue`:
  - Multi-select checkboxes in table header and rows.
  - Sticky/floating batch action toolbar with "Sync to Cameras" and "Delete Selected" buttons.
  - Accessible confirmation dialogs (using `notify.confirm()`, zero `window.confirm()`).
  - Integration with `BulkCampaignProgressModal`.
- Create wrapper re-export components ensuring `test_f26` passes:
  - `resources/js/components/devices/DeviceManager.vue`
  - `resources/js/components/personnel/PersonnelManager.vue`

### 6. Verification Commands
Run each command and document exact outputs in your handoff report:
1. `php artisan test --filter="test_f2[0-6]"`
2. `php artisan test --filter="test_boundary_bulk"`
3. `php artisan test --filter="test_scenario_8"`
4. `php artisan test --filter=E2E`
5. `php artisan test` (Verify 100% pass across all tests, 0 failures!)
6. `npm run build` (Verify clean Vite bundle with exit code 0!)

Write your handoff report to `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m4_1_rep/handoff.md` and notify parent via `send_message`.


## 2026-10-08T22:31:53Z
You are worker_m4_1_rep.
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m4_1_rep
Your dispatch directive is at: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m4_1_rep/DISPATCH.md
Your parent is conversation ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb
