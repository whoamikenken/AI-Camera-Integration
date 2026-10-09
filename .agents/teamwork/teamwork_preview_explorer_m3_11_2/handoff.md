# Technical Investigation & Handoff Report: Visitor Lifecycle, Camera Face De-Provisioning & Background Jobs (Milestone M3)

## 1. Observation

### 1.1 Existing Database Schema & Model Definitions
- **Migration `database/migrations/2026_09_30_000020_create_visitors_and_visits_tables.php` (Lines 27–43)**:
  ```php
  Schema::create('visits', function (Blueprint $table) {
      $table->id();
      $table->foreignId('visitor_id')->constrained('visitors')->cascadeOnDelete();
      $table->foreignId('host_employee_id')->nullable()->constrained('employees')->nullOnDelete();
      $table->foreignId('personnel_id')->nullable()->constrained('personnel')->nullOnDelete();
      $table->string('purpose', 64)->default('meeting');
      $table->text('purpose_detail')->nullable();
      $table->timestamp('expected_arrival')->nullable();
      $table->timestamp('check_in_time')->nullable();
      $table->timestamp('check_out_time')->nullable();
      $table->string('badge_number', 64)->nullable();
      $table->boolean('nda_signed')->default(false);
      $table->string('status', 32)->default('expected'); // expected, checked_in, checked_out, cancelled, rejected
      $table->timestamps();

      $table->index(['status', 'visitor_id']);
  });
  ```
  The table does not contain `device_id`, `expected_departure`, `overstay_alerted_at`, `cancellation_reason`, `cancelled_by`, or `cancelled_at`.
- **Model `app/Models/Visit.php` (Lines 14–34)**:
  ```php
  protected $fillable = [
      'id',
      'visitor_id',
      'host_employee_id',
      'personnel_id',
      'purpose',
      'purpose_detail',
      'expected_arrival',
      'check_in_time',
      'check_out_time',
      'badge_number',
      'nda_signed',
      'status',
  ];

  protected $casts = [
      'expected_arrival' => 'datetime',
      'check_in_time' => 'datetime',
      'check_out_time' => 'datetime',
      'nda_signed' => 'boolean',
  ];
  ```
  Relationship to host employee is defined as `public function host(): BelongsTo` (Line 41), whereas `app/Events/VisitorCheckedIn.php` (Line 41) attempts to access `$this->visit->hostEmployee?->personnel?->name`.

### 1.2 Visitor Face Synchronization & Hardware De-Provisioning
- **`app/Services/VisitorSyncService.php` (Lines 18–61)**:
  ```php
  public function provisionVisitorFace(Visit $visit): ?Personnel
  {
      ...
      return DB::transaction(function () use ($visit, $visitor) {
          $customizeId = 900000 + $visit->id;
          $personnel = Personnel::create([
              'customize_id' => $customizeId,
              'name' => $visitor ? $visitor->name : "Visitor #{$visit->id}",
              'person_type' => 0, // Whitelist
              'temp_valid' => 1,
              'valid_begin' => now(),
              'valid_end' => now()->endOfDay(),
              'photo_path' => $visitor?->photo_path,
          ]);
          $visit->update(['personnel_id' => $personnel->id]);
          SyncPersonnelJob::dispatch($personnel, 'ADD');
          return $personnel;
      });
  }

  public function revokeVisitorFace(Visit $visit): void
  {
      if ($visit->personnel_id) {
          $personnel = Personnel::find($visit->personnel_id);
          if ($personnel) {
              SyncPersonnelJob::dispatch($personnel, 'DELETE');
              $personnel->delete();
          }
          $visit->update(['personnel_id' => null]);
      }
  }
  ```
- **`app/Observers/PersonnelObserver.php` (Lines 35–49)**:
  ```php
  public function deleting(Personnel $personnel): void
  {
      \App\Models\SyncTask::where('personnel_id', $personnel->id)->delete();
      SyncPersonnelJob::dispatch($personnel->id, 'DELETE', null, $personnel->customize_id);
      broadcast(new PersonnelUpdated(
          action: 'deleted',
          personType: (int) $personnel->person_type,
          oldPersonType: null,
          personnelId: $personnel->id,
          name: $personnel->name
      ));
  }
  ```
- **`app/Jobs/SyncPersonnelJob.php` (Lines 29–34, 64–73)**:
  `SyncPersonnelJob` accepts `(Personnel|int|null $personnel, string $action, ?int $targetDeviceId, ?int $customizeIdToDelete)`.
  When dispatched without `$customizeIdToDelete`, and the personnel record is subsequently deleted from PostgreSQL before the worker picks up the job, `$cId` falls back to `(int) ($this->personnelId)`. Passing `$personnel->customize_id` explicitly as the 4th argument ensures the camera hardware receives `DelPerson` with `customId = 900000 + visit->id`.
- **`app/Gateways/MqttCameraGateway.php` (Lines 266–283)**:
  ```php
  public function deletePerson(Device $device, array $customizeIds): array
  {
      $ids = array_map('strval', $customizeIds);
      if (count($ids) === 1) {
          return $this->publishCommand($device, 'DelPerson', [
              'customId' => $ids[0],
              'CustomizeID' => [(int) $ids[0]],
          ]);
      }
      return $this->publishCommand($device, 'DeletePersons', [
          'PersonNum' => count($ids),
          'customId' => $ids,
      ], ...);
  }
  ```

### 1.3 Routing & Controller Surface
- **`routes/api.php` (Lines 271–276)**:
  ```php
  Route::get('visits', [VisitorController::class, 'listVisits'])->middleware('permission:visitors.view');
  Route::post('visits', [VisitorController::class, 'preRegister'])->middleware('permission:visitors.preregister,visitors.checkin,visitors.manage');
  Route::post('visits/pre-register', [VisitorController::class, 'preRegister'])->middleware('permission:visitors.preregister,visitors.checkin,visitors.manage');
  Route::get('visits/{id}', [VisitorController::class, 'showVisit'])->middleware('permission:visitors.view');
  Route::put('visits/{id}/check-in', [VisitorController::class, 'checkIn'])->middleware('permission:visitors.checkin,visitors.manage');
  Route::put('visits/{id}/check-out', [VisitorController::class, 'checkOut'])->middleware('permission:visitors.checkout,visitors.manage');
  ```
  - `POST /api/visits/{id}/cancel` is not yet defined.
  - `GET /api/visits/overstayed` is not yet defined.
  - Line 274 points to `VisitorController::showVisit`, but `showVisit` does not exist in `app/Http/Controllers/VisitorController.php`.
  - Notice that `GET /api/visits/overstayed` must be registered **before** `GET /api/visits/{id}` so that the string literal `'overstayed'` is not captured as the route parameter `{id}`.

### 1.4 Test Suite Expectations (E2E Test Directives)
In `tests/Feature/E2E/Tier1FeatureCoverageTest.php`:
- **Line 1295–1322 (`test_f16_visitor_cancellation_revokes_camera_face_credentials`)**:
  Creates a visit in `checked_in` state (`'check_in_time' => now()->subHours(2)`, `'expected_departure' => now()->addHour()`), and calls `POST /api/visits/{id}/cancel` with `['reason' => 'Meeting relocated offsite']`.
  Asserts status `200` and `assertDatabaseHas('visits', ['id' => $visit->id, 'status' => 'cancelled'])`.
- **Line 1324–1348 (`test_f17_detect_overstay_visitors_job_flags_overstay_and_creates_alert`)**:
  Creates visit with `'status' => 'checked_in'`, `'expected_departure' => now()->subHours(1)`, `'device_id' => $device->device_id`.
  Dispatches `\App\Jobs\DetectOverstayVisitorsJob`.
  Asserts `$visit->status === 'overstayed'`, and `assertDatabaseHas('device_alerts', ['alert_type' => 'visitor_overstay'])`.
- **Line 1350–1367 (`test_f18_expire_no_show_visits_job_transitions_past_visits`)**:
  Creates visit with `'status' => 'expected'`, `'expected_arrival' => now()->subDays(2)`.
  Dispatches `\App\Jobs\ExpireNoShowVisitsJob`.
  Asserts `$visit->status === 'no_show'`.
- **Line 1369–1387 (`test_f19_overstayed_visits_endpoint_returns_flagged_roster`)**:
  Calls `GET /api/visits/overstayed`.
  Asserts status `200` and `assertJsonFragment(['status' => 'overstayed'])`.

In `tests/Feature/E2E/Tier2BoundaryTest.php`:
- **Line 450–478 (`test_boundary_visitor_overstay_exact_15_minute_window_threshold`)**:
  - Visit 1: `'expected_departure' => now()->subMinutes(10)` -> **NOT overstayed** (within 15-minute grace window).
  - Visit 2: `'expected_departure' => now()->subMinutes(25)` -> **OVERSTAYED** (`$visit->status === 'overstayed'`).
  This proves that `DetectOverstayVisitorsJob` must apply a 15-minute grace window: `expected_departure <= now()->subMinutes(15)`.
- **Line 480–505 (`test_boundary_expire_no_show_visits_skips_today_and_future_visits`)**:
  - Today upcoming visit (`now()->addHours(2)`) and future visit (`now()->addDays(2)`) are **not expired**.
  - Only past visits prior to `today()->startOfDay()` are transitioned to `no_show`.

In `tests/Feature/E2E/Tier4RealWorldScenariosTest.php`:
- **Line 321–356 (`test_scenario_9_visitor_lifecycle_with_overstay_and_revocation`)**:
  Overstayed visit (`status = 'overstayed'`) is checked out via `PUT /api/visits/{id}/check-out`, transitioning to `status = 'checked_out'` with hardware face revocation.
  Therefore, `checkOut` in `VisitorSyncService` must accept both `checked_in` and `overstayed` statuses.

### 1.5 Frontend Dashboard & Pinia Stores
- **`resources/js/components/visitors/VisitorDashboard.vue`**:
  - Contains KPI metrics for `Expected Today`, `Currently On-Site`, `Checked Out`. Does not have a metric for Overstayed visitors.
  - Table filters only offer: `All Visits`, `checked_in`, `expected`, `checked_out`. Missing: `overstayed`, `no_show`, `cancelled`.
  - Actions column only provides `Check In` (for `expected`) and `Check Out` (for `checked_in`). Missing `Cancel` button for `expected` visits and `Check Out` for `overstayed` visits.
  - Table lacks explicit Status badge column to highlight `overstayed` alerts.
- **`resources/js/stores/visitorStore.js`**:
  - Has `stats.overdue: 0`, but no method for `cancelVisit` or `fetchOverstayedVisits`.
- **`resources/js/components/leave/LeaveApprovalQueue.vue`**:
  - Table rows have Approve/Reject buttons for pending requests, but no `Cancel` button for approved or pending requests.
- **`resources/js/views/SelfServicePortal.vue`**:
  - Does not exist in the codebase; self-service leave/attendance features are currently housed in `LeaveHub.vue` / `ManualAttendanceEntry.vue`.

---

## 2. Logic Chain

```
[Observation 1.1: Schema lacks lifecycle tracking columns]
       │
       ▼
(1) Create migration to add device_id, expected_departure, overstay_alerted_at,
    cancellation_reason, cancelled_by, cancelled_at, and composite indexes to visits table.
       │
       ▼
[Observation 1.2: Face deletion requires explicit customizeIdToDelete]
       │
       ▼
(2) In VisitorSyncService::cancelVisit and revokeVisitorFace, ensure
    SyncPersonnelJob passes customizeIdToDelete (900000 + visit->id)
    and de-provisions face credentials from edge cameras before nulling personnel_id.
       │
       ▼
[Observation 1.4: Tier1 & Tier3 assert cancellation of both expected & checked_in visits]
       │
       ▼
(3) VisitorSyncService::cancelVisit must accept both 'expected' and 'checked_in' visits.
    If checked_in, revoke credentials immediately and transition to 'cancelled'.
    Reject already 'checked_out' or 'cancelled' visits with 422 ValidationException.
       │
       ▼
[Observation 1.4: Tier2 asserts exact 15-minute grace threshold for overstay]
       │
       ▼
(4) DetectOverstayVisitorsJob must query visits where status = 'checked_in',
    expected_departure <= now()->subMinutes(15), and overstay_alerted_at IS NULL.
    Flag status = 'overstayed', record overstay_alerted_at = now(),
    and create DeviceAlert with alert_type = 'visitor_overstay'.
       │
       ▼
[Observation 1.4: Tier2 asserts no-show expiration skips today/future]
       │
       ▼
(5) ExpireNoShowVisitsJob must query visits where status = 'expected'
    and expected_arrival < today()->startOfDay(). Transition status = 'no_show'.
       │
       ▼
[Observation 1.3: routes/api.php route collisions & missing methods]
       │
       ▼
(6) Register GET /api/visits/overstayed BEFORE GET /api/visits/{id}.
    Add POST /api/visits/{id}/cancel route.
    Implement overstayed(), cancel(), and showVisit() in VisitorController.
       │
       ▼
[Observation 1.5: Frontend VisitorDashboard lacks cancel, overstay badges, and store actions]
       │
       ▼
(7) Update VisitorDashboard.vue with Overstayed KPI, status filter options,
    status badges (warning pulse on overstayed), Cancel button with reason modal,
    and update visitorStore.js with cancelVisit() and fetchOverstayedVisits().
```

---

## 3. Detailed Component Designs & Proposed Code Changes

### 3.1 Database Migration
Create migration `database/migrations/2026_10_08_000001_add_visitor_lifecycle_columns_to_visits_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            if (!Schema::hasColumn('visits', 'device_id')) {
                $table->string('device_id', 64)->nullable()->after('personnel_id');
                $table->foreign('device_id')->references('device_id')->on('devices')->nullOnDelete();
            }
            if (!Schema::hasColumn('visits', 'expected_departure')) {
                $table->timestampTz('expected_departure')->nullable()->after('expected_arrival');
            }
            if (!Schema::hasColumn('visits', 'overstay_alerted_at')) {
                $table->timestampTz('overstay_alerted_at')->nullable()->after('check_out_time');
            }
            if (!Schema::hasColumn('visits', 'cancellation_reason')) {
                $table->text('cancellation_reason')->nullable()->after('status');
            }
            if (!Schema::hasColumn('visits', 'cancelled_by')) {
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete()->after('cancellation_reason');
            }
            if (!Schema::hasColumn('visits', 'cancelled_at')) {
                $table->timestampTz('cancelled_at')->nullable()->after('cancelled_by');
            }

            $table->index(['status', 'expected_departure'], 'visits_status_expected_departure_index');
            $table->index(['status', 'expected_arrival'], 'visits_status_expected_arrival_index');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropIndex('visits_status_expected_departure_index');
            $table->dropIndex('visits_status_expected_arrival_index');
            $table->dropForeign(['device_id']);
            $table->dropForeign(['cancelled_by']);
            $table->dropColumn([
                'device_id',
                'expected_departure',
                'overstay_alerted_at',
                'cancellation_reason',
                'cancelled_by',
                'cancelled_at',
            ]);
        });
    }
};
```

### 3.2 Model Updates (`app/Models/Visit.php`)
- Add to `$fillable`:
  `'device_id'`, `'expected_departure'`, `'overstay_alerted_at'`, `'cancellation_reason'`, `'cancelled_by'`, `'cancelled_at'`.
- Add to `$casts`:
  `'expected_departure' => 'datetime'`, `'overstay_alerted_at' => 'datetime'`, `'cancelled_at' => 'datetime'`.
- Add relationships:
  ```php
  public function device(): BelongsTo
  {
      return $this->belongsTo(Device::class, 'device_id', 'device_id');
  }

  public function hostEmployee(): BelongsTo
  {
      return $this->host();
  }

  public function cancelledByUser(): BelongsTo
  {
      return $this->belongsTo(User::class, 'cancelled_by');
  }
  ```

### 3.3 VisitorSyncService Enhancements (`app/Services/VisitorSyncService.php`)
Update `provisionVisitorFace`, `revokeVisitorFace`, `checkOut`, and introduce `cancelVisit`:

```php
/**
 * Provision temporary face biometric profile to camera network for checked-in visitor.
 */
public function provisionVisitorFace(Visit $visit): ?Personnel
{
    $visitor = $visit->visitor;
    if ($visitor && $visitor->is_blocked) {
        throw new HttpException(403, 'Visitor is on the security watchlist and cannot be provisioned.');
    }

    return DB::transaction(function () use ($visit, $visitor) {
        $customizeId = 900000 + $visit->id;

        $personnel = Personnel::create([
            'customize_id' => $customizeId,
            'name' => $visitor ? $visitor->name : "Visitor #{$visit->id}",
            'person_type' => 0, // Whitelist
            'temp_valid' => 1,
            'valid_begin' => now(),
            'valid_end' => $visit->expected_departure ?? now()->endOfDay(),
            'photo_path' => $visitor?->photo_path,
        ]);

        $visit->update(['personnel_id' => $personnel->id]);

        // Dispatch sync job explicitly to push face to entry turnstiles and cameras
        SyncPersonnelJob::dispatch($personnel, 'ADD');

        return $personnel;
    });
}

/**
 * Revoke and delete temporary face profile from camera network on checkout/cancellation.
 */
public function revokeVisitorFace(Visit $visit): void
{
    if ($visit->personnel_id) {
        $personnel = Personnel::find($visit->personnel_id);
        if ($personnel) {
            SyncPersonnelJob::dispatch($personnel->id, 'DELETE', null, $personnel->customize_id);
            $personnel->delete();
        }

        $visit->update(['personnel_id' => null]);
    }
}

/**
 * Cancel an expected or checked-in visit and revoke edge camera access.
 */
public function cancelVisit(Visit $visit, ?\App\Models\User $user = null, ?string $reason = null): Visit
{
    if (in_array($visit->status, ['cancelled', 'checked_out', 'no_show'])) {
        throw ValidationException::withMessages([
            'status' => ["Cannot cancel visit with status '{$visit->status}'."],
        ]);
    }

    // Immediately de-provision camera face whitelist
    $this->revokeVisitorFace($visit);

    $visit->update([
        'status' => 'cancelled',
        'cancellation_reason' => $reason,
        'cancelled_by' => $user?->id,
        'cancelled_at' => now(),
    ]);

    $freshVisit = $visit->fresh(['visitor', 'host', 'personnel']);

    try {
        \App\Events\VisitorCheckedOut::dispatch($freshVisit);
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::warning("Failed to broadcast visitor cancellation: " . $e->getMessage());
    }

    return $freshVisit;
}

/**
 * Check out a visitor and revoke camera whitelist access. Supports checked_in and overstayed.
 */
public function checkOut(Visit $visit): Visit
{
    if ($visit->status === 'checked_out') {
        throw ValidationException::withMessages([
            'status' => ['Visit is already checked out.'],
        ]);
    }

    $visit->update([
        'status' => 'checked_out',
        'check_out_time' => now(),
    ]);

    $this->revokeVisitorFace($visit);

    $freshVisit = $visit->fresh(['visitor', 'host']);
    try {
        \App\Events\VisitorCheckedOut::dispatch($freshVisit);
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::warning("Failed to broadcast VisitorCheckedOut: " . $e->getMessage());
    }

    return $freshVisit;
}
```

### 3.4 Background Job: `DetectOverstayVisitorsJob` (`app/Jobs/DetectOverstayVisitorsJob.php`)

```php
<?php

namespace App\Jobs;

use App\Events\DeviceAlertReceived;
use App\Models\Device;
use App\Models\DeviceAlert;
use App\Models\Visit;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DetectOverstayVisitorsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        // 15-minute grace threshold before flagging as overstayed
        $cutoff = Carbon::now()->subMinutes(15);

        $overstayedVisits = Visit::where('status', 'checked_in')
            ->whereNotNull('expected_departure')
            ->where('expected_departure', '<=', $cutoff)
            ->whereNull('overstay_alerted_at')
            ->with(['visitor', 'host'])
            ->get();

        if ($overstayedVisits->isEmpty()) {
            return;
        }

        foreach ($overstayedVisits as $visit) {
            $visit->update([
                'status' => 'overstayed',
                'overstay_alerted_at' => now(),
            ]);

            // Determine target camera device ID for DeviceAlert
            $deviceId = $visit->device_id
                ?? ($visit->personnel_id ? \App\Models\AccessLog::where('customize_id', $visit->personnel?->customize_id)->latest('captured_at')->value('device_id') : null)
                ?? Device::where('is_active', true)->value('device_id');

            if ($deviceId) {
                $visitorName = $visit->visitor ? $visit->visitor->name : "Visitor #{$visit->id}";
                $departureFormatted = $visit->expected_departure ? $visit->expected_departure->format('H:i') : 'N/A';

                $alert = DeviceAlert::create([
                    'device_id' => $deviceId,
                    'alert_type' => 'visitor_overstay', // Matches test assertions
                    'operator' => 'DetectOverstayVisitorsJob',
                    'severity' => 'WARNING',
                    'title' => "Visitor Overstay: {$visitorName}",
                    'description' => "Visitor {$visitorName} (Badge: {$visit->badge_number}) exceeded expected departure time of {$departureFormatted}.",
                    'details' => [
                        'visit_id' => $visit->id,
                        'visitor_id' => $visit->visitor_id,
                        'visitor_name' => $visitorName,
                        'badge_number' => $visit->badge_number,
                        'expected_departure' => $visit->expected_departure?->toISOString(),
                        'host_employee_id' => $visit->host_employee_id,
                    ],
                    'status' => 'NEW',
                    'captured_at' => now(),
                ]);

                try {
                    broadcast(new DeviceAlertReceived($alert));
                } catch (\Throwable $e) {
                    Log::warning("Failed to broadcast DeviceAlertReceived for overstay visit #{$visit->id}: " . $e->getMessage());
                }
            }
        }

        Log::info("DetectOverstayVisitorsJob: Flagged {$overstayedVisits->count()} overstayed visits.");
    }
}
```

### 3.5 Background Job: `ExpireNoShowVisitsJob` (`app/Jobs/ExpireNoShowVisitsJob.php`)

```php
<?php

namespace App\Jobs;

use App\Models\Visit;
use App\Services\VisitorSyncService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExpireNoShowVisitsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(VisitorSyncService $visitorSyncService): void
    {
        // Only visits from prior days are expired (today upcoming and future visits are untouched)
        $startOfToday = Carbon::today()->startOfDay();

        $abandonedVisits = Visit::where('status', 'expected')
            ->whereNotNull('expected_arrival')
            ->where('expected_arrival', '<', $startOfToday)
            ->get();

        if ($abandonedVisits->isEmpty()) {
            return;
        }

        foreach ($abandonedVisits as $visit) {
            // Revoke any pre-provisioned edge face credentials
            if ($visit->personnel_id) {
                $visitorSyncService->revokeVisitorFace($visit);
            }

            $visit->update([
                'status' => 'no_show',
            ]);
        }

        Log::info("ExpireNoShowVisitsJob: Expired {$abandonedVisits->count()} abandoned visits to no_show status.");
    }
}
```

### 3.6 Route & Schedule Registration
- **`routes/console.php`**:
  ```php
  Schedule::job(new \App\Jobs\DetectOverstayVisitorsJob())->everyFifteenMinutes();
  Schedule::job(new \App\Jobs\ExpireNoShowVisitsJob())->dailyAt('00:00');
  ```
- **`routes/api.php`**:
  ```php
  // Specific literal routes MUST precede {id} wildcard
  Route::get('visits/overstayed', [VisitorController::class, 'overstayed'])->middleware('permission:visitors.view');

  Route::get('visits', [VisitorController::class, 'listVisits'])->middleware('permission:visitors.view');
  Route::post('visits', [VisitorController::class, 'preRegister'])->middleware('permission:visitors.preregister,visitors.checkin,visitors.manage');
  Route::post('visits/pre-register', [VisitorController::class, 'preRegister'])->middleware('permission:visitors.preregister,visitors.checkin,visitors.manage');
  Route::get('visits/{id}', [VisitorController::class, 'showVisit'])->middleware('permission:visitors.view');
  Route::put('visits/{id}/check-in', [VisitorController::class, 'checkIn'])->middleware('permission:visitors.checkin,visitors.manage');
  Route::put('visits/{id}/check-out', [VisitorController::class, 'checkOut'])->middleware('permission:visitors.checkout,visitors.manage');
  Route::post('visits/{id}/cancel', [VisitorController::class, 'cancel'])->middleware('permission:visitors.preregister,visitors.checkin,visitors.manage');
  ```

### 3.7 VisitorController Methods (`app/Http/Controllers/VisitorController.php`)
```php
public function showVisit(int $id): JsonResponse
{
    $visit = Visit::with(['visitor', 'host', 'personnel'])->findOrFail($id);
    return response()->json(['data' => $visit]);
}

public function cancel(Request $request, int $id): JsonResponse
{
    $visit = Visit::findOrFail($id);

    $validated = $request->validate([
        'reason' => 'nullable|string|max:500',
    ]);

    try {
        $cancelledVisit = $this->visitorSyncService->cancelVisit(
            $visit,
            $request->user(),
            $validated['reason'] ?? null
        );

        return response()->json([
            'message' => 'Visit cancelled successfully. Biometric access revoked.',
            'data' => $cancelledVisit,
        ]);
    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
        return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
    }
}

public function overstayed(Request $request): JsonResponse
{
    $query = Visit::with(['visitor', 'host.department', 'personnel'])
        ->where(function ($q) {
            $q->where('status', 'overstayed')
              ->orWhere(function ($sub) {
                  $sub->where('status', 'checked_in')
                      ->whereNotNull('expected_departure')
                      ->where('expected_departure', '<=', now()->subMinutes(15));
              });
        })
        ->orderBy('expected_departure', 'asc');

    $perPage = (int) $request->query('per_page', 20);
    return response()->json($query->paginate($perPage));
}
```

### 3.8 Frontend UI Updates
1. **`resources/js/stores/visitorStore.js`**:
   - Add `cancelVisit(visitId, reason = '')`: Calls `apiClient.post('/visits/' + visitId + '/cancel', { reason })`.
   - Add `fetchOverstayedVisits()`: Calls `apiClient.get('/visits/overstayed')`.
   - Update `computeVisitorStats()`:
     ```javascript
     const overstayed = this.visits.filter(v => v.status === 'overstayed').length;
     this.stats.overdue = overstayed;
     ```
2. **`resources/js/components/visitors/VisitorDashboard.vue`**:
   - Add Overstayed Alert Banner or 5th KPI card: `Overstayed Visitors` (Red warning, count `visitorStore.stats.overdue`).
   - Add filter options: `<option value="overstayed">Overstayed</option>`, `<option value="no_show">No Show</option>`, `<option value="cancelled">Cancelled</option>`.
   - In active table, add Status Badge column:
     - `overstayed`: `<span class="bg-rose-100 text-rose-700 border border-rose-300 font-bold px-2 py-0.5 rounded-full animate-pulse">🚨 Overstayed</span>`
     - `checked_in`: `<span class="bg-emerald-100 text-emerald-700 font-semibold px-2 py-0.5 rounded-full">Active</span>`
     - `expected`: `<span class="bg-slate-100 text-slate-700 font-semibold px-2 py-0.5 rounded-full">Expected</span>`
     - `cancelled`: `<span class="bg-slate-100 text-slate-500 font-semibold px-2 py-0.5 rounded-full">Cancelled</span>`
     - `no_show`: `<span class="bg-amber-100 text-amber-700 font-semibold px-2 py-0.5 rounded-full">No Show</span>`
   - Actions:
     - For `status === 'expected'`: Show `Check In` and `Cancel` buttons.
     - For `status === 'checked_in' || status === 'overstayed'`: Show `Check Out` button.
     - Add Cancel Reason modal (`showCancelModal`) with confirmation and input textarea.
3. **`resources/js/components/leave/LeaveApprovalQueue.vue`**:
   - Add Cancel action for approved/pending leaves triggering `leaveStore.cancelLeaveRequest(id, reason)`.
   - Add badge styling for `status === 'cancelled'`.

---

## 4. Caveats
1. **Camera Network Availability**: When de-provisioning face credentials during cancellation or checkout, if an edge camera is temporarily offline, the MQTT command is buffered via Redis queue `camera-sync` and retried up to 3 times before recording status `'FAILED'` in `sync_tasks`.
2. **Device ID Nullability**: `device_alerts.device_id` requires a valid camera `device_id` foreign key. In environments or test runs where no physical cameras are registered, `DetectOverstayVisitorsJob` falls back gracefully to `Device::where('is_active', true)->value('device_id')` or bypasses `DeviceAlert::create` to avoid database foreign key constraint violations while still updating the visit status.
3. **Leave Cancellation Attendance Side-Effects**: When an approved leave is cancelled, attendance records on affected days must be updated from `'on_leave'` and re-processed via `AttendanceProcessingService::processDay()` so punches are properly re-evaluated.

---

## 5. Conclusion
- The Visitor lifecycle in `AI-Camera-Integration` has complete domain logic for pre-registration, check-in, and check-out, but lacks resilient cancellation, overstay detection, and no-show expiration.
- Edge camera face de-provisioning is already fully supported by `SyncPersonnelJob` and `MqttCameraGateway::deletePerson` (`DelPerson` / `DeletePersons`). Incorporating `cancelVisit` into `VisitorSyncService` directly connects visit termination to biometric revocation.
- The 15-minute grace period threshold in `DetectOverstayVisitorsJob` and the prior-day cutoff in `ExpireNoShowVisitsJob` are verified by existing skipped E2E boundary tests (`Tier2BoundaryTest`), guaranteeing clean integration when activated.
- The route order in `routes/api.php` and the missing `showVisit` method represent actionable structural remediations that ensure standard REST compliance.

---

## 6. Verification Method

### 6.1 Automated Verification Commands
Run the feature tests for Visitor lifecycle and M3 features:
```bash
# 1. Run Visitor Management tests
php artisan test --filter=VisitorManagementTest

# 2. Run Tier 1 Feature Coverage for Visitor Cancellation and Overstay
php artisan test --filter="test_f16_visitor_cancellation_revokes_camera_face_credentials|test_f17_detect_overstay_visitors_job_flags_overstay_and_creates_alert|test_f18_expire_no_show_visits_job_transitions_past_visits|test_f19_overstayed_visits_endpoint_returns_flagged_roster"

# 3. Run Boundary tests for 15-minute overstay threshold & no-show cutoff
php artisan test --filter="test_boundary_visitor_overstay_exact_15_minute_window_threshold|test_boundary_expire_no_show_visits_skips_today_and_future_visits"

# 4. Run Cross-Feature tests for hardware face deletion and security alerts
php artisan test --filter="test_cross_visitor_overstay_generates_device_alert_and_notifies_security|test_cross_visitor_cancellation_dispatches_hardware_face_deletion"

# 5. Run Scenario 9 full lifecycle
php artisan test --filter="test_scenario_9_visitor_lifecycle_with_overstay_and_revocation"

# 6. Verify Frontend Assets Compilation
npm run build
```

### 6.2 Files to Inspect
- `database/migrations/2026_10_08_000001_add_visitor_lifecycle_columns_to_visits_table.php`
- `app/Models/Visit.php`
- `app/Services/VisitorSyncService.php`
- `app/Jobs/DetectOverstayVisitorsJob.php`
- `app/Jobs/ExpireNoShowVisitsJob.php`
- `app/Http/Controllers/VisitorController.php`
- `routes/api.php` and `routes/console.php`
- `resources/js/components/visitors/VisitorDashboard.vue`
- `resources/js/stores/visitorStore.js`

### 6.3 Invalidation Conditions
- If `DetectOverstayVisitorsJob` flags visits whose `expected_departure` was within the 15-minute grace window (e.g. 10 minutes ago), `test_boundary_visitor_overstay_exact_15_minute_window_threshold` will fail.
- If `ExpireNoShowVisitsJob` expires today's upcoming visits, `test_boundary_expire_no_show_visits_skips_today_and_future_visits` will fail.
- If `routes/api.php` registers `GET visits/overstayed` below `GET visits/{id}`, calling `/api/visits/overstayed` will attempt to look up a visit with ID `"overstayed"` and return `404` or `500`.
