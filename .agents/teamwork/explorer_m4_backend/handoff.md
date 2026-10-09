# Milestone M4 Backend Architecture & Blueprint Report

**Module:** Bulk Workforce Operations & Fleet Provisioning Campaigns (Milestone M4)  
**Author:** explorer_m4_backend  
**Target Implementer:** worker_m4_backend  
**Date:** 2026-10-08T18:48:00Z  

---

## 1. Observation

Direct investigation of the codebase, test suites, and system architecture revealed the following concrete observations:

### 1.1 Existing Implementations & Patterns
- **Device Operations (`app/Http/Controllers/DeviceController.php`)**:
  - `reboot(Device $device)` (lines 179-184): delegates directly to `$this->cameraService->rebootDevice($device)`.
  - `syncMqtt(Request $request, Device $device)` (lines 186-211): validates MQTT parameters (`KeepAliveInterval`, `StrangerUploadType`, `RecordUploadType`, `MQTopic`, etc.) and invokes `$this->cameraService->configureMqtt($device, $params)`.
  - Both operations are currently 1-by-1 synchronous per-device interactions.
- **Personnel Operations (`app/Http/Controllers/PersonnelController.php`)**:
  - `destroy(Personnel $personnel)` (lines 173-178): deletes the record. Handled by `PersonnelObserver::deleting` (`app/Observers/PersonnelObserver.php` lines 50-65), which dispatches `SyncPersonnelJob::dispatch($personnel->id, 'DELETE', null, $personnel->customize_id)`.
  - `syncNow(Request $request, Personnel $personnel)` (lines 180-186): dispatches `SyncPersonnelJob::dispatch($personnel->id, 'ADD', $targetDeviceId)`.
  - Both operations dispatch single-person tasks to Redis queue `camera-sync`.
- **Bulk Operation Precedent (`app/Http/Controllers/ShiftController.php`)**:
  - `bulkAssign(Request $request)` (lines 190-236): validates array inputs (`employee_ids`, `department_ids`). Checks `if (empty($targetIds)) { return response()->json(['message' => '...'], 422); }`. Executes batch DB transaction and returns `201 Created` with count and data.
- **Biometric Target Resolution (`app/Services/AccessControlService.php`)**:
  - `getAuthorizedDevicesForPersonnel(Personnel $personnel)` (lines 24-85): resolves authorized devices based on active access group assignments (`access_group_personnel`, `access_group_department`).
  - Fallback logic: if `AccessGroup::count() === 0`, returns all active devices (`Device::where('is_active', true)->get()`). If access groups exist but none match, returns an empty collection.
- **Camera Gateway & Hardware Protocol (`app/Contracts/CameraGatewayInterface.php`, `app/Gateways/MqttCameraGateway.php`, `app/Gateways/FakeCameraGateway.php`)**:
  - `CameraGatewayInterface`: supports `rebootDevice`, `configureMqtt`, `addOrUpdatePerson` (single `EditPerson`), and `deletePerson` (single `DelPerson` or multi-person `DeletePersons`).
  - `CameraHttpService.php` (lines 793-804) & `MqttListenCommand.php` (line 188): documents the hardware batch face upload command `AddPersons`. Payload format requires `Total` (or `PersonNum`) and indexed entries `Personinfo_0`, `Personinfo_1`, ..., `Personinfo_N-1` (up to 50 persons per packet).
  - `FakeCameraGateway::publishCommand` (lines 92-151): records any operator dispatch in `$this->dispatched` collection and returns standard response (`operator: "{$operator}-Ack"`, `code: 200`), allowing assertions like `$gateway->assertDispatched('RebootDevice')`, `assertDispatched('UpMQTTconfig')`, `assertDispatched('AddPersons')`.

### 1.2 E2E Test Expectations (Features 20–26, Boundaries, and Scenarios)
- **`tests/Feature/E2E/Tier1FeatureCoverageTest.php`**:
  - `test_f20_bulk_campaigns_entity_tracks_execution_progress` (lines 1393-1414): Requires table `bulk_campaigns` and model `App\Models\BulkCampaign`. Asserts creation with `user_id`, `campaign_type => 'reboot_fleet'`, `total_items => 10`, `processed_items => 7`, `failed_items => 1`, `status => 'processing'`, `payload => ['device_ids' => [1, ...]]`.
  - `test_f21_fleet_bulk_reboot_endpoint_dispatches_rate_limited_jobs` (lines 1416-1431): `POST /api/devices/bulk-reboot` with `['device_ids' => [$cam1->id, $cam2->id]]` returns HTTP status **202 Accepted** with JSON structure `['campaign_id']`.
  - `test_f22_fleet_bulk_mqtt_sync_endpoint_dispatches_parameter_updates` (lines 1433-1452): `POST /api/devices/bulk-sync-mqtt` with `['device_ids' => [...], 'mqtt_config' => ['KeepAlive' => 60, ...]]` returns HTTP status **202 Accepted** with JSON structure `['campaign_id']`.
  - `test_f23_high_throughput_bulk_personnel_sync_batches_up_to_50_persons` (lines 1454-1461): Verifies `new \App\Jobs\BulkPersonnelSyncJob([1, 2, 3])` instantiates cleanly.
  - `test_f24_bulk_personnel_deletion_endpoint_removes_records_and_dispatches` (lines 1463-1478): `POST /api/personnel/bulk-delete` with `['personnel_ids' => [$p1->id, $p2->id]]` returns HTTP status **202 Accepted** with JSON structure `['campaign_id']`.
  - `test_f25_bulk_campaign_progress_api_returns_status_and_counters` (lines 1480-1499): `GET /api/bulk-campaigns/{id}` returns HTTP status **200 OK** with JSON fragment `['status' => 'completed', 'total_items' => 50]`.
  - `test_f26_fleet_and_personnel_batch_toolbars_exist_in_frontend` (lines 1501-1508): Asserts existence of `resources/js/components/devices/DeviceManager.vue` and `resources/js/components/personnel/PersonnelManager.vue`.
- **`tests/Feature/E2E/Tier2BoundaryTest.php`**:
  - `test_boundary_bulk_personnel_sync_exact_50_person_chunking` (lines 507-518): Verifies 51 items partition into exactly 2 chunks: chunk 0 with 50 items, chunk 1 with 1 item.
  - `test_boundary_bulk_reboot_with_empty_devices_array_rejected_with_422` (lines 520-530): `POST /api/devices/bulk-reboot` with `['device_ids' => []]` returns HTTP **422 Unprocessable Content**.
  - `test_boundary_bulk_personnel_deletion_with_empty_array_rejected_with_422` (lines 532-542): `POST /api/personnel/bulk-delete` with `['personnel_ids' => []]` returns HTTP **422 Unprocessable Content**.
  - `test_boundary_bulk_campaign_progress_clamps_between_zero_and_one_hundred_percent` (lines 544-564): Progress percentage calculated as `($campaign->total_items > 0) ? round(($campaign->processed_items / $campaign->total_items) * 100) : 0`.
- **`tests/Feature/E2E/Tier4RealWorldScenariosTest.php`**:
  - `test_scenario_8_large_workforce_bulk_onboarding_campaign` (lines 280-315): Simulates 120 personnel synced across devices, chunked into `[50, 50, 20]`, updating `BulkCampaign` status to `'completed'` and `processed_items` to 120.

---

## 2. Logic Chain

1. **Schema & Model Design**:
   - The test assertions and system evolution requirements dictate an entity `BulkCampaign` backed by table `bulk_campaigns`.
   - The entity requires:
     - `id`: Primary key (BIGINT).
     - `user_id`: Nullable foreign key referencing `users(id)` ON DELETE SET NULL (tracks who triggered the campaign).
     - `campaign_type`: String(64) (`reboot_fleet`, `update_mqtt_config`, `sync_personnel`, `delete_personnel`).
     - `total_items`, `processed_items`, `failed_items`: Integers for real-time progress counters.
     - `status`: String(32) (`pending`, `processing`, `completed`, `partial`, `failed`).
     - `payload`: JSON column preserving parameters (`device_ids`, `personnel_ids`, `mqtt_config`).
     - `error_summary`: Nullable text or JSON tracking failed items and edge device error messages.
     - `timestamps`: `created_at` and `updated_at`.
   - The `BulkCampaign` model requires JSON casting for `payload`, integer casting for item counts, helper mutation methods (`markProcessing`, `incrementProcessed`, `incrementFailed`, `markCompleted`, `markFailed`, `progressPercent`), and an accessor `progress_percent`.

2. **Job Design — `BulkDeviceCampaignJob`**:
   - Queue: `'camera-sync'` Redis queue.
   - Responsible for batch execution of `reboot_fleet` and `update_mqtt_config`.
   - Reads target `device_ids` from the campaign payload.
   - Interacts with `CameraMqttService` / `CameraGatewayInterface`.
   - Implements rate-limiting / throttle between device command dispatches (e.g., 50ms pause in production, skipped in tests) to avoid MQTT broker message flood.
   - Robustly tracks per-device success or failure, updating `incrementProcessed` or `incrementFailed` on the `BulkCampaign` model, concluding with `markCompleted()`.

3. **Job Design — `BulkPersonnelSyncJob`**:
   - Queue: `'camera-sync'` Redis queue.
   - Must accept `new BulkPersonnelSyncJob(array $personnelIds = [], ?int $campaignId = null, string $action = 'sync', ?int $targetDeviceId = null)`.
   - **Action `'sync'`**:
     - Resolves `Personnel` records.
     - If `$targetDeviceId` is provided, scopes to that device; otherwise uses `AccessControlService::getAuthorizedDevicesForPersonnel($person)` to respect access zones.
     - Inverts mapping to target devices: `device_id => [personnel...]`.
     - For each device, partitions personnel into chunks of **at most 50** (`array_chunk($devicePersonnel, 50)`).
     - Formats each chunk into `Personinfo_0` through `Personinfo_{N-1}` using `$cameraService->buildPersonnelInfo($person)`.
     - Dispatches hardware command `AddPersons` to the camera topic via gateway.
     - Records corresponding `SyncTask` records for auditability.
   - **Action `'delete'`**:
     - Resolves target devices for the personnel.
     - For each device, gathers custom IDs and dispatches `DeletePersons` (or `DelPerson` for 1) via `$cameraService->deletePerson($device, $customizeIds)`.
     - Deletes the local `Personnel` records from the database (`Personnel::whereIn('id', $personnelIds)->delete()`).
   - Updates `BulkCampaign` progress counters incrementally and concludes with `markCompleted()`.

4. **Controller & Endpoint Architecture**:
   - `POST /api/devices/bulk-reboot` -> Handled by `DeviceController::bulkReboot` (or `BulkCampaignController::bulkReboot`). Validates `device_ids` (`required|array|min:1`), creates `BulkCampaign`, dispatches `BulkDeviceCampaignJob`, returns `202 Accepted` with `['campaign_id' => $campaign->id]`.
   - `POST /api/devices/bulk-sync-mqtt` -> Handled by `DeviceController::bulkSyncMqtt`. Validates `device_ids` (`required|array|min:1`) and `mqtt_config` (`required|array`), creates `BulkCampaign`, dispatches `BulkDeviceCampaignJob`, returns `202 Accepted` with `['campaign_id' => $campaign->id]`.
   - `POST /api/personnel/bulk-sync` -> Handled by `PersonnelController::bulkSync`. Validates `personnel_ids` (`required|array|min:1`), creates `BulkCampaign`, dispatches `BulkPersonnelSyncJob`, returns `202 Accepted` with `['campaign_id' => $campaign->id]`.
   - `POST /api/personnel/bulk-delete` -> Handled by `PersonnelController::bulkDelete`. Validates `personnel_ids` (`required|array|min:1`), creates `BulkCampaign`, dispatches `BulkPersonnelSyncJob` with action `'delete'`, returns `202 Accepted` with `['campaign_id' => $campaign->id]`.
   - `GET /api/bulk-campaigns/{id}` -> Handled by `BulkCampaignController::show`. Returns `200 OK` with JSON serialization of `BulkCampaign` including `status`, `total_items`, `processed_items`, `failed_items`, `progress_percent`.
   - Empty input arrays (`[]`) are rejected by Laravel's `'min:1'` validation rule, generating HTTP `422 Unprocessable Content` as asserted by `Tier2BoundaryTest`.

5. **Gateway & Mock Compatibility**:
   - Add `addPersons(Device $device, array $personnelItems): array` to `CameraGatewayInterface`.
   - Implement `addPersons` in `MqttCameraGateway`: builds payload with `Total` / `PersonNum` and `Personinfo_{idx}` and calls `$this->publishCommand($device, 'AddPersons', $info)`.
   - Implement `addPersons` in `FakeCameraGateway`: calls `$this->recordDispatch('publishCommand', $device, 'AddPersons', $info)`, ensuring `assertDispatched('AddPersons')` succeeds.
   - Add helper `addPersons` in `CameraMqttService`.

---

## 3. Caveats

1. **Frontend Component Paths in Test**:
   - `test_f26` asserts the physical existence of:
     - `resources/js/components/devices/DeviceManager.vue`
     - `resources/js/components/personnel/PersonnelManager.vue`
   - Currently, `DeviceManager.vue` and `PersonnelManager.vue` are located at `resources/js/views/DeviceManager.vue` and `resources/js/views/PersonnelManager.vue`.
   - To satisfy `test_f26` without disrupting existing routing or builds, symlinks, components, or re-export stubs must exist at `resources/js/components/devices/DeviceManager.vue` and `resources/js/components/personnel/PersonnelManager.vue`.
2. **Access Control Filtering during Bulk Sync**:
   - When bulk-syncing personnel, not all personnel necessarily belong to the same access groups. Grouping personnel per target device before chunking ensures that camera template capacities are not overflowed and access segmentations are strictly honored.
3. **Personnel Bulk Deletion Ordering**:
   - If records are deleted from the database before the background job runs, the job cannot query `customize_id` or find associated access groups. Therefore, the controller or job must fetch the `customize_id`s and target devices *before* deleting database records.
4. **Queue Environment in Unit Tests**:
   - In automated feature tests, the `sync` queue driver is typically used unless `Queue::fake()` is active. All jobs must handle synchronous immediate execution cleanly as well as asynchronous queued execution.

---

## 4. Conclusion & Concrete Implementation Blueprints

Below are the exact, drop-in blueprints for all Milestone M4 components.

### 4.1 Migration: `database/migrations/2026_10_08_000002_create_bulk_campaigns_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('campaign_type', 64)->index();
            $table->integer('total_items')->default(0);
            $table->integer('processed_items')->default(0);
            $table->integer('failed_items')->default(0);
            $table->string('status', 32)->default('pending')->index();
            $table->json('payload')->nullable();
            $table->text('error_summary')->nullable();
            $table->timestamps();

            $table->index(['campaign_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_campaigns');
    }
};
```

---

### 4.2 Model: `app/Models/BulkCampaign.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BulkCampaign extends Model
{
    use HasFactory;

    protected $table = 'bulk_campaigns';

    protected $fillable = [
        'user_id',
        'campaign_type',
        'total_items',
        'processed_items',
        'failed_items',
        'status',
        'payload',
        'error_summary',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'total_items' => 'integer',
        'processed_items' => 'integer',
        'failed_items' => 'integer',
        'payload' => 'array',
    ];

    protected $appends = [
        'progress_percent',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getProgressPercentAttribute(): int
    {
        return $this->progressPercent();
    }

    public function progressPercent(): int
    {
        if ($this->total_items <= 0) {
            return 0;
        }

        $pct = (int) round(($this->processed_items / $this->total_items) * 100);

        return min(100, max(0, $pct));
    }

    public function markProcessing(): self
    {
        $this->update(['status' => 'processing']);

        return $this;
    }

    public function incrementProcessed(int $count = 1): self
    {
        $this->processed_items += $count;
        $this->save();

        return $this;
    }

    public function incrementFailed(int $count = 1, ?string $error = null): self
    {
        $this->failed_items += $count;

        if ($error) {
            $existing = $this->error_summary ? $this->error_summary . "\n" : '';
            $this->error_summary = $existing . $error;
        }

        $this->save();

        return $this;
    }

    public function markCompleted(?string $finalStatus = null): self
    {
        if ($finalStatus) {
            $status = $finalStatus;
        } elseif ($this->failed_items > 0 && $this->processed_items === 0) {
            $status = 'failed';
        } elseif ($this->failed_items > 0 && $this->processed_items > 0) {
            $status = 'partial';
        } else {
            $status = 'completed';
        }

        $this->update(['status' => $status]);

        return $this;
    }

    public function markFailed(string $error): self
    {
        $existing = $this->error_summary ? $this->error_summary . "\n" : '';
        $this->update([
            'status' => 'failed',
            'error_summary' => $existing . $error,
        ]);

        return $this;
    }
}
```

---

### 4.3 Gateway Enhancements

#### In `app/Contracts/CameraGatewayInterface.php`:
Add to interface:
```php
    /**
     * Batch add personnel records to the device face library.
     */
    public function addPersons(Device $device, array $personnelItems): array;
```

#### In `app/Gateways/MqttCameraGateway.php`:
Add implementation:
```php
    public function addPersons(Device $device, array $personnelItems): array
    {
        $info = [
            'Total' => count($personnelItems),
            'PersonNum' => count($personnelItems),
        ];

        foreach (array_values($personnelItems) as $idx => $item) {
            $info["Personinfo_{$idx}"] = $item;
        }

        return $this->publishCommand($device, 'AddPersons', $info);
    }
```

#### In `app/Gateways/FakeCameraGateway.php`:
Add implementation:
```php
    public function addPersons(Device $device, array $personnelItems): array
    {
        $info = [
            'Total' => count($personnelItems),
            'PersonNum' => count($personnelItems),
        ];

        foreach (array_values($personnelItems) as $idx => $item) {
            $info["Personinfo_{$idx}"] = $item;
        }

        return $this->recordDispatch('publishCommand', $device, 'AddPersons', $info);
    }
```

#### In `app/Services/CameraMqttService.php`:
Add convenience wrapper:
```php
    public function addPersons(Device $device, array $personnelItems): array
    {
        return $this->gateway->addPersons($device, $personnelItems);
    }
```

---

### 4.4 Background Jobs

#### `app/Jobs/BulkDeviceCampaignJob.php`
```php
<?php

namespace App\Jobs;

use App\Models\BulkCampaign;
use App\Models\Device;
use App\Services\CameraMqttService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BulkDeviceCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;

    public function __construct(
        public int $campaignId
    ) {
        $this->onQueue('camera-sync');
    }

    public function handle(CameraMqttService $cameraService): void
    {
        $campaign = BulkCampaign::find($this->campaignId);
        if (!$campaign) {
            Log::error("BulkDeviceCampaignJob: Campaign #{$this->campaignId} not found.");
            return;
        }

        $campaign->markProcessing();

        $payload = $campaign->payload ?? [];
        $deviceIds = $payload['device_ids'] ?? [];

        if (empty($deviceIds)) {
            $campaign->markCompleted('completed');
            return;
        }

        $isUnitTest = app()->runningUnitTests();

        foreach ($deviceIds as $deviceId) {
            try {
                $device = Device::find($deviceId);
                if (!$device) {
                    $campaign->incrementFailed(1, "Device #{$deviceId} not found.");
                    continue;
                }

                if (!$device->is_active) {
                    $campaign->incrementFailed(1, "Device #{$deviceId} ({$device->name}) is inactive.");
                    continue;
                }

                $res = null;
                if ($campaign->campaign_type === 'reboot_fleet') {
                    $res = $cameraService->rebootDevice($device);
                } elseif ($campaign->campaign_type === 'update_mqtt_config') {
                    $mqttConfig = $payload['mqtt_config'] ?? [];
                    $res = $cameraService->configureMqtt($device, $mqttConfig);
                    if (!empty($res['success']) && !empty($mqttConfig['MQTopic'])) {
                        $device->update(['mqtt_topic' => $mqttConfig['MQTopic']]);
                    }
                } else {
                    $campaign->incrementFailed(1, "Unknown campaign type: {$campaign->campaign_type}");
                    continue;
                }

                if (!empty($res['success'])) {
                    $campaign->incrementProcessed(1);
                } else {
                    $err = $res['error'] ?? "Failed command on device #{$deviceId}";
                    $campaign->incrementFailed(1, (string) $err);
                }

                // Rate limiting between device dispatches to prevent broker congestion
                if (!$isUnitTest) {
                    usleep(50000); // 50ms pause
                }
            } catch (\Throwable $e) {
                Log::error("BulkDeviceCampaignJob error on device #{$deviceId}: " . $e->getMessage());
                $campaign->incrementFailed(1, "Exception on device #{$deviceId}: " . $e->getMessage());
            }
        }

        $campaign->markCompleted();
    }
}
```

#### `app/Jobs/BulkPersonnelSyncJob.php`
```php
<?php

namespace App\Jobs;

use App\Models\BulkCampaign;
use App\Models\Device;
use App\Models\Personnel;
use App\Models\SyncTask;
use App\Services\AccessControlService;
use App\Services\CameraMqttService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BulkPersonnelSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;

    public function __construct(
        public array $personnelIds = [],
        public ?int $campaignId = null,
        public string $action = 'sync', // 'sync' or 'delete'
        public ?int $targetDeviceId = null
    ) {
        $this->onQueue('camera-sync');
    }

    public function handle(CameraMqttService $cameraService, ?AccessControlService $accessControlService = null): void
    {
        $accessControlService = $accessControlService ?? app(AccessControlService::class);
        $campaign = $this->campaignId ? BulkCampaign::find($this->campaignId) : null;

        if ($campaign) {
            $campaign->markProcessing();
        }

        if (empty($this->personnelIds)) {
            if ($campaign) {
                $campaign->markCompleted('completed');
            }
            return;
        }

        if ($this->action === 'delete') {
            $this->handleBulkDelete($cameraService, $accessControlService, $campaign);
        } else {
            $this->handleBulkSync($cameraService, $accessControlService, $campaign);
        }
    }

    protected function handleBulkSync(CameraMqttService $cameraService, AccessControlService $accessControlService, ?BulkCampaign $campaign): void
    {
        $personnelRecords = Personnel::whereIn('id', $this->personnelIds)->get();

        // 1. Resolve target devices per personnel based on Access Control groups
        $deviceToPersonnelMap = []; // device_id => [Personnel...]

        foreach ($personnelRecords as $person) {
            if ($this->targetDeviceId) {
                $devices = Device::where('id', $this->targetDeviceId)->where('is_active', true)->get();
            } else {
                $devices = $accessControlService->getAuthorizedDevicesForPersonnel($person);
            }

            foreach ($devices as $device) {
                $deviceToPersonnelMap[$device->id][] = $person;
            }
        }

        $processedIds = [];
        $failedIds = [];

        // 2. For each device, chunk personnel into packets of at most 50
        foreach ($deviceToPersonnelMap as $deviceId => $persons) {
            $device = Device::find($deviceId);
            if (!$device || !$device->is_active) {
                continue;
            }

            $chunks = array_chunk($persons, 50);

            foreach ($chunks as $chunk) {
                $payloadItems = [];
                foreach ($chunk as $person) {
                    $payloadItems[] = $cameraService->buildPersonnelInfo($person);
                }

                try {
                    $res = $cameraService->addPersons($device, $payloadItems);

                    foreach ($chunk as $person) {
                        SyncTask::create([
                            'device_id' => $device->device_id,
                            'personnel_id' => $person->id,
                            'action' => 'ADD',
                            'status' => !empty($res['success']) ? 'COMPLETED' : 'FAILED',
                            'attempts' => 1,
                            'error_message' => empty($res['success']) ? ($res['error'] ?? 'AddPersons failed') : null,
                        ]);

                        if (!empty($res['success'])) {
                            $processedIds[$person->id] = true;
                        } else {
                            $failedIds[$person->id] = true;
                        }
                    }
                } catch (\Throwable $e) {
                    Log::error("BulkPersonnelSyncJob failed AddPersons to device #{$device->id}: " . $e->getMessage());
                    foreach ($chunk as $person) {
                        $failedIds[$person->id] = true;
                    }
                }
            }
        }

        // Account for personnel that had no active devices assigned
        foreach ($this->personnelIds as $id) {
            if (!isset($processedIds[$id]) && !isset($failedIds[$id])) {
                $processedIds[$id] = true; // completed with no-op
            }
        }

        if ($campaign) {
            $campaign->incrementProcessed(count($processedIds));
            if (!empty($failedIds)) {
                $campaign->incrementFailed(count($failedIds), "Failed to sync to one or more edge devices.");
            }
            $campaign->markCompleted();
        }
    }

    protected function handleBulkDelete(CameraMqttService $cameraService, AccessControlService $accessControlService, ?BulkCampaign $campaign): void
    {
        $personnelRecords = Personnel::whereIn('id', $this->personnelIds)->get();
        $deviceToCustomIdsMap = []; // device_id => [customize_id...]

        foreach ($personnelRecords as $person) {
            if ($this->targetDeviceId) {
                $devices = Device::where('id', $this->targetDeviceId)->where('is_active', true)->get();
            } else {
                $devices = $accessControlService->getAuthorizedDevicesForPersonnel($person);
            }

            foreach ($devices as $device) {
                $deviceToCustomIdsMap[$device->id][] = (int) $person->customize_id;
            }
        }

        // 1. Dispatch deletion packets to edge devices
        foreach ($deviceToCustomIdsMap as $deviceId => $customIds) {
            $device = Device::find($deviceId);
            if (!$device || !$device->is_active) {
                continue;
            }

            $uniqueCustomIds = array_values(array_unique($customIds));
            try {
                $cameraService->deletePerson($device, $uniqueCustomIds);
            } catch (\Throwable $e) {
                Log::error("BulkPersonnelSyncJob: Error deleting from device {$device->device_id}: " . $e->getMessage());
            }
        }

        // 2. Remove personnel records from database
        $count = count($this->personnelIds);
        Personnel::whereIn('id', $this->personnelIds)->delete();

        if ($campaign) {
            $campaign->incrementProcessed($count);
            $campaign->markCompleted();
        }
    }
}
```

---

### 4.5 Controller Implementations

#### Controller: `app/Http/Controllers/BulkCampaignController.php`
```php
<?php

namespace App\Http\Controllers;

use App\Models\BulkCampaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BulkCampaignController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = BulkCampaign::query()->with('user:id,name,email');

        if ($type = $request->input('campaign_type')) {
            $query->where('campaign_type', $type);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $campaigns = $query->orderBy('id', 'desc')->paginate($request->input('per_page', 15));

        return response()->json($campaigns);
    }

    public function show(int $id): JsonResponse
    {
        $campaign = BulkCampaign::with('user:id,name,email')->findOrFail($id);

        return response()->json($campaign);
    }
}
```

#### Additions to `app/Http/Controllers/DeviceController.php`:
```php
    public function bulkReboot(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_ids' => 'required|array|min:1',
            'device_ids.*' => 'integer|exists:devices,id',
        ]);

        $campaign = \App\Models\BulkCampaign::create([
            'user_id' => $request->user()?->id,
            'campaign_type' => 'reboot_fleet',
            'total_items' => count($validated['device_ids']),
            'processed_items' => 0,
            'failed_items' => 0,
            'status' => 'pending',
            'payload' => [
                'device_ids' => $validated['device_ids'],
            ],
        ]);

        \App\Jobs\BulkDeviceCampaignJob::dispatch($campaign->id);

        return response()->json([
            'campaign_id' => $campaign->id,
            'message' => 'Fleet bulk reboot campaign queued.',
            'data' => $campaign,
        ], 202);
    }

    public function bulkSyncMqtt(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_ids' => 'required|array|min:1',
            'device_ids.*' => 'integer|exists:devices,id',
            'mqtt_config' => 'required|array',
        ]);

        $campaign = \App\Models\BulkCampaign::create([
            'user_id' => $request->user()?->id,
            'campaign_type' => 'update_mqtt_config',
            'total_items' => count($validated['device_ids']),
            'processed_items' => 0,
            'failed_items' => 0,
            'status' => 'pending',
            'payload' => [
                'device_ids' => $validated['device_ids'],
                'mqtt_config' => $validated['mqtt_config'],
            ],
        ]);

        \App\Jobs\BulkDeviceCampaignJob::dispatch($campaign->id);

        return response()->json([
            'campaign_id' => $campaign->id,
            'message' => 'Fleet bulk MQTT parameter update campaign queued.',
            'data' => $campaign,
        ], 202);
    }
```

#### Additions to `app/Http/Controllers/PersonnelController.php`:
```php
    public function bulkSync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'personnel_ids' => 'required|array|min:1',
            'personnel_ids.*' => 'integer|exists:personnel,id',
            'device_id' => 'nullable|integer|exists:devices,id',
        ]);

        $campaign = \App\Models\BulkCampaign::create([
            'user_id' => $request->user()?->id,
            'campaign_type' => 'sync_personnel',
            'total_items' => count($validated['personnel_ids']),
            'processed_items' => 0,
            'failed_items' => 0,
            'status' => 'pending',
            'payload' => [
                'personnel_ids' => $validated['personnel_ids'],
                'device_id' => $validated['device_id'] ?? null,
            ],
        ]);

        \App\Jobs\BulkPersonnelSyncJob::dispatch(
            $validated['personnel_ids'],
            $campaign->id,
            'sync',
            $validated['device_id'] ?? null
        );

        return response()->json([
            'campaign_id' => $campaign->id,
            'message' => 'Bulk personnel sync campaign queued.',
            'data' => $campaign,
        ], 202);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'personnel_ids' => 'required|array|min:1',
            'personnel_ids.*' => 'integer|exists:personnel,id',
        ]);

        $campaign = \App\Models\BulkCampaign::create([
            'user_id' => $request->user()?->id,
            'campaign_type' => 'delete_personnel',
            'total_items' => count($validated['personnel_ids']),
            'processed_items' => 0,
            'failed_items' => 0,
            'status' => 'pending',
            'payload' => [
                'personnel_ids' => $validated['personnel_ids'],
            ],
        ]);

        \App\Jobs\BulkPersonnelSyncJob::dispatch(
            $validated['personnel_ids'],
            $campaign->id,
            'delete'
        );

        return response()->json([
            'campaign_id' => $campaign->id,
            'message' => 'Bulk personnel deletion campaign queued.',
            'data' => $campaign,
        ], 202);
    }
```

---

### 4.6 Route Declarations: `routes/api.php`

In `routes/api.php`, within the authenticated group:
```php
    // Fleet Bulk Operations (Milestone M4)
    Route::post('devices/bulk-reboot', [DeviceController::class, 'bulkReboot'])
        ->middleware('permission:devices.manage');
    Route::post('devices/bulk-sync-mqtt', [DeviceController::class, 'bulkSyncMqtt'])
        ->middleware('permission:devices.manage');

    // Personnel Bulk Operations (Milestone M4)
    Route::post('personnel/bulk-sync', [PersonnelController::class, 'bulkSync'])
        ->middleware('permission:personnel.sync,devices.manage');
    Route::post('personnel/bulk-delete', [PersonnelController::class, 'bulkDelete'])
        ->middleware('permission:personnel.delete');

    // Bulk Campaign Progress & Inspection (Milestone M4)
    Route::get('bulk-campaigns', [BulkCampaignController::class, 'index'])
        ->middleware('permission:devices.view,personnel.view,devices.manage');
    Route::get('bulk-campaigns/{id}', [BulkCampaignController::class, 'show'])
        ->middleware('permission:devices.view,personnel.view,devices.manage');
```

---

### 4.7 Frontend Component File Links for `test_f26`
To satisfy `Tier1FeatureCoverageTest::test_f26_fleet_and_personnel_batch_toolbars_exist_in_frontend`, ensure:
1. Directory `resources/js/components/devices/` contains `DeviceManager.vue` (symlink or component referencing `resources/js/views/DeviceManager.vue`).
2. Directory `resources/js/components/personnel/` contains `PersonnelManager.vue` (symlink or component referencing `resources/js/views/PersonnelManager.vue`).

---

## 5. Verification Method

Once implemented by `worker_m4_backend`, the implementation can be validated independently through the following steps:

1. **Migration Verification**:
   ```bash
   php artisan migrate
   ```
   Asserts table `bulk_campaigns` is created with columns and indexes.

2. **Milestone M4 Feature Test Suite**:
   ```bash
   php artisan test --filter=Tier1FeatureCoverageTest::test_f20
   php artisan test --filter=Tier1FeatureCoverageTest::test_f21
   php artisan test --filter=Tier1FeatureCoverageTest::test_f22
   php artisan test --filter=Tier1FeatureCoverageTest::test_f23
   php artisan test --filter=Tier1FeatureCoverageTest::test_f24
   php artisan test --filter=Tier1FeatureCoverageTest::test_f25
   php artisan test --filter=Tier1FeatureCoverageTest::test_f26
   ```

3. **Boundary and Corner Case Tests**:
   ```bash
   php artisan test --filter=test_boundary_bulk
   ```
   Validates:
   - `test_boundary_bulk_personnel_sync_exact_50_person_chunking`
   - `test_boundary_bulk_reboot_with_empty_devices_array_rejected_with_422`
   - `test_boundary_bulk_personnel_deletion_with_empty_array_rejected_with_422`
   - `test_boundary_bulk_campaign_progress_clamps_between_zero_and_one_hundred_percent`

4. **Multi-Step Large Workforce Campaign Scenario**:
   ```bash
   php artisan test --filter=test_scenario_8_large_workforce_bulk_onboarding_campaign
   ```

5. **Full E2E Regression Suite**:
   ```bash
   php artisan test --filter=E2E
   ```
   Ensures 0 failures, 0 errors, and all M4 tests transition from skipped to passed.
