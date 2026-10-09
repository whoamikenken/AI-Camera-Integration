# Milestone M2 Handoff Report: Access Control Groups API Routes, Controller & Frontend Manager

**Agent**: `teamwork_preview_explorer_m2_3`  
**Milestone**: M2 (Access Control Groups & Zone-Based Dispatching) — Features 11 & 12  
**Target Areas**: API Routes (`routes/api.php`), Controller (`App\Http\Controllers\AccessGroupController.php`), Frontend Component (`resources/js/components/settings/AccessGroupManager.vue`), Navigation (`resources/js/components/settings/SettingsHub.vue`), and Test Suite Coverage (`Tier1FeatureCoverageTest::test_f11`, `test_f12`, `Tier3CrossFeatureTest::test_cross_access_group_zone_resync...`).

---

## 1. Observation

### Observation 1.1: Missing Routes in `routes/api.php`
- In `routes/api.php`:
  Lines 114-156 declare endpoints for `devices` and `personnel`. No route referencing `access-groups` exists in `routes/api.php`:
  ```bash
  grep -n "access-groups" routes/api.php
  # Result: 0 matches
  ```
- Line 54 guards authenticated routes with:
  ```php
  Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {
  ```
- Existing controller permission middleware pattern:
  - Device endpoints use `middleware('permission:devices.view,devices.manage')` and `middleware('permission:devices.manage')`.
  - Personnel sync endpoint in line 154 uses:
    ```php
    Route::post('personnel/{personnel}/sync-now', [PersonnelController::class, 'syncNow'])->middleware('permission:personnel.sync');
    ```

### Observation 1.2: Test Assertions in `Tier1FeatureCoverageTest`
- In `tests/Feature/E2E/Tier1FeatureCoverageTest.php:1136-1159`:
  ```php
  1136:     public function test_f11_access_group_zone_resync_endpoint_dispatches_roster(): void
  1137:     {
  1138:         $this->requireRoute('/api/access-groups/1/sync-now', 'POST', 'Milestone 2');
  1139:         $this->requireTable('access_groups', 'Milestone 2');
  1140: 
  1141:         $this->actingAsAdmin();
  1142:         $group = \App\Models\AccessGroup::create([
  1143:             'id' => 1,
  1144:             'name' => 'Zone Sync Test',
  1145:             'code' => 'ZONE-SYNC-TEST',
  1146:             'is_active' => true,
  1147:         ]);
  1148:         $device = $this->createTestDevice();
  1149:         $group->devices()->attach($device->id);
  1150: 
  1151:         $response = $this->postJson('/api/access-groups/' . $group->id . '/sync-now');
  1152:         $response->assertStatus(200);
  1153:     }
  1154: 
  1155:     public function test_f12_access_group_manager_vue_component_exists(): void
  1156:     {
  1157:         $this->requireFile('resources/js/components/settings/AccessGroupManager.vue', 'Milestone 2');
  1158:         $this->assertFileExists(base_path('resources/js/components/settings/AccessGroupManager.vue'));
  1159:     }
  ```
- In `tests/Feature/E2E/Tier3CrossFeatureTest.php:346-366`:
  ```php
  346:     public function test_cross_access_group_zone_resync_pushes_all_members_to_all_group_devices(): void
  347:     {
  348:         $this->requireRoute('/api/access-groups/1/sync-now', 'POST', 'Milestone 2');
  349:         $this->requireTable('access_groups', 'Milestone 2');
  350: 
  351:         $this->actingAsAdmin();
  352:         $cam = $this->createTestDevice();
  353:         $personnel = $this->createTestPersonnel();
  354: 
  355:         $group = \App\Models\AccessGroup::create([
  356:             'id' => 1,
  357:             'name' => 'Resync Campus',
  358:             'code' => 'RESYNC-CAMPUS',
  359:             'is_active' => true,
  360:         ]);
  361:         $group->devices()->attach($cam->id);
  362:         $group->personnel()->attach($personnel->id);
  363: 
  364:         $response = $this->postJson('/api/access-groups/' . $group->id . '/sync-now');
  365:         $response->assertStatus(200);
  366:     }
  ```
- Observations:
  1. `requireRoute('/api/access-groups/1/sync-now', 'POST', 'Milestone 2')` normalizes path parameters to `/api/access-groups/{param}/sync-now`.
  2. `test_f11` tests a group with 1 device and 0 personnel. Response must be HTTP 200 without throwing zero-division or null-pointer errors.
  3. `test_cross...` tests a group with 1 device and 1 personnel. Response must be HTTP 200.
  4. `test_f12` asserts `file_exists(base_path('resources/js/components/settings/AccessGroupManager.vue'))`.

### Observation 1.3: Non-Existent Controller & Component
- `app/Http/Controllers/AccessGroupController.php` does not exist in the codebase.
- `resources/js/components/settings/AccessGroupManager.vue` does not exist in the codebase.

### Observation 1.4: Navigation Integration in `SettingsHub.vue`
- In `resources/js/components/settings/SettingsHub.vue`:
  - Lines 40-47 define tab array:
    ```javascript
    42: const tabs = [
    43:   { id: 'departments', label: 'Organization & Departments', icon: '🏢' },
    44:   { id: 'system', label: 'System Parameters', icon: '⚙️' },
    45:   { id: 'audit', label: 'Audit Trail', icon: '📋' },
    46: ];
    ```
  - Lines 26-30 render active view:
    ```html
    26:     <div>
    27:       <DepartmentManager v-if="activeTab === 'departments'" />
    28:       <SystemSettings v-else-if="activeTab === 'system'" />
    29:       <AuditLogViewer v-else-if="activeTab === 'audit'" />
    30:     </div>
    ```
  - In `resources/js/App.vue:515`:
    `<SettingsHub v-else-if="currentTab === 'settings'" />` is rendered when the Settings tab is active.

---

## 2. Logic Chain

1. **Route Matching & Middleware Alignment**:
   - *Observation 1.1 & 1.2*: `requireRoute` in `E2ETestCase` matches normalized route parameter signatures against registered routes in `routes/api.php`.
   - Registering explicit routes:
     - `GET /api/access-groups`
     - `POST /api/access-groups`
     - `GET /api/access-groups/{id}`
     - `PUT /api/access-groups/{id}`
     - `DELETE /api/access-groups/{id}`
     - `POST /api/access-groups/{id}/sync-now`
     inside the Sanctum authenticated group satisfies `requireRoute('/api/access-groups/1/sync-now', 'POST')` and allows seamless CRUD operations.
   - Permissions `permission:devices.manage` and `permission:personnel.sync` exist in `RolesAndPermissionsSeeder.php` and align with the existing security architecture.

2. **Controller Implementation Requirements**:
   - *Observation 1.2 & 1.3*: `AccessGroupController` must provide methods `index`, `store`, `show`, `update`, `destroy`, and `syncNow`.
   - In `store` and `update`: `device_ids`, `personnel_ids`, and `department_ids` arrays must be synchronized to their respective pivots (`access_group_device`, `access_group_personnel`, `access_group_department`) within a `DB::transaction()`.
   - In `syncNow`:
     - Must query `$group->devices()->where('is_active', true)->get()`.
     - Must collect direct personnel (`$group->personnel`) AND departmental personnel (`Department -> Employee -> Personnel`).
     - Must dispatch sync jobs (`SyncDevicePersonnelJob` / `SyncPersonnelJob`) for all members across all group devices.
     - When personnel list or device list is empty (as in `test_f11`), it must return HTTP 200 with `{ success: true, dispatched_count: 0 }`.

3. **Frontend Component Requirements**:
   - *Observation 1.2 & 1.4*: `test_f12` asserts the exact file path `resources/js/components/settings/AccessGroupManager.vue`.
   - `PROJECT.md` line 28 specifies: `AccessGroupManager.vue in Settings Hub`.
   - By embedding `<AccessGroupManager v-else-if="activeTab === 'access-groups'" />` into `SettingsHub.vue` with tab `{ id: 'access-groups', label: 'Access Groups & Zones', icon: '🛡️' }`, administrators can manage physical security zones directly from the System Settings Hub.
   - The component must adhere to project standards:
     - WCAG 2.1 AA accessible labels (`aria-label`, table `scope="col"`, `role="dialog"`, `@keydown.escape`).
     - Animated 5-row pulse skeleton table to eliminate Cumulative Layout Shift (CLS).
     - SweetAlert2 modal confirmations via `notify.confirm(...)` for destructive deletion and zone re-synchronization.

---

## 3. Caveats

1. **Database Schema Dependency**:
   - `AccessGroupController` depends on `access_groups` and pivots (`access_group_device`, `access_group_personnel`, `access_group_department`) being migrated by Milestone M2 migrations (handled in parallel by explorer `m2_1`).
2. **Service Delegation**:
   - If `AccessControlService::syncZone` is available from Milestone M2 services (handled in parallel by explorer `m2_2`), `AccessGroupController::syncNow` should invoke it; if not, it should fall back to directly dispatching `SyncDevicePersonnelJob` for resilient execution.
3. **Personnel Department Relationship**:
   - Departmental personnel resolution depends on `Personnel` having an associated `Employee` record with `department_id`. Personnel without employee records are included via direct `access_group_personnel` assignment.

---

## 4. Conclusion & Proposed Implementation

### 4.1 Route Declarations (`routes/api.php`)
Insert around line 146 in `routes/api.php`:
```php
    // Access Control Groups & Security Zones (Milestone M2)
    Route::get('access-groups', [\App\Http\Controllers\AccessGroupController::class, 'index'])
        ->middleware('permission:devices.view,devices.manage,personnel.view');
    Route::post('access-groups', [\App\Http\Controllers\AccessGroupController::class, 'store'])
        ->middleware('permission:devices.manage');
    Route::get('access-groups/{id}', [\App\Http\Controllers\AccessGroupController::class, 'show'])
        ->middleware('permission:devices.view,devices.manage,personnel.view');
    Route::put('access-groups/{id}', [\App\Http\Controllers\AccessGroupController::class, 'update'])
        ->middleware('permission:devices.manage');
    Route::delete('access-groups/{id}', [\App\Http\Controllers\AccessGroupController::class, 'destroy'])
        ->middleware('permission:devices.manage');
    Route::post('access-groups/{id}/sync-now', [\App\Http\Controllers\AccessGroupController::class, 'syncNow'])
        ->middleware('permission:devices.manage,personnel.sync');
```

### 4.2 Proposed Controller: `app/Http/Controllers/AccessGroupController.php`
```php
<?php

namespace App\Http\Controllers;

use App\Jobs\SyncDevicePersonnelJob;
use App\Models\AccessGroup;
use App\Models\Personnel;
use App\Services\AccessControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccessGroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AccessGroup::query()
            ->with(['organization:id,name,code'])
            ->withCount(['devices', 'personnel', 'departments']);

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('code', 'ilike', "%{$search}%")
                  ->orWhere('description', 'ilike', "%{$search}%");
            });
        }

        if ($request->has('is_active') && $request->input('is_active') !== '') {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->query('organization_id'));
        }

        $query->orderBy('name');

        if ($request->boolean('all')) {
            return response()->json([
                'success' => true,
                'data' => $query->get(),
            ]);
        }

        return response()->json($query->paginate($request->input('per_page', 15)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:128',
            'code' => 'required|string|max:64|unique:access_groups,code',
            'description' => 'nullable|string|max:255',
            'organization_id' => 'nullable|exists:organizations,id',
            'is_active' => 'nullable|boolean',
            'device_ids' => 'nullable|array',
            'device_ids.*' => 'integer|exists:devices,id',
            'personnel_ids' => 'nullable|array',
            'personnel_ids.*' => 'integer|exists:personnel,id',
            'department_ids' => 'nullable|array',
            'department_ids.*' => 'integer|exists:departments,id',
        ]);

        $group = DB::transaction(function () use ($validated, $request) {
            $grp = AccessGroup::create([
                'name' => $validated['name'],
                'code' => $validated['code'],
                'description' => $validated['description'] ?? null,
                'organization_id' => $validated['organization_id'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
            ]);

            if ($request->has('device_ids')) {
                $grp->devices()->sync($request->input('device_ids', []));
            }
            if ($request->has('personnel_ids')) {
                $grp->personnel()->sync($request->input('personnel_ids', []));
            }
            if ($request->has('department_ids')) {
                $grp->departments()->sync($request->input('department_ids', []));
            }

            return $grp;
        });

        $group->load(['devices', 'personnel', 'departments', 'organization']);
        $group->loadCount(['devices', 'personnel', 'departments']);

        return response()->json([
            'success' => true,
            'message' => 'Access group created successfully.',
            'data' => $group,
            'id' => $group->id,
        ], 201);
    }

    public function show($id): JsonResponse
    {
        $group = AccessGroup::with(['devices', 'personnel', 'departments', 'organization'])
            ->withCount(['devices', 'personnel', 'departments'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $group,
            'id' => $group->id,
        ]);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $group = AccessGroup::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:128',
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:64',
                Rule::unique('access_groups', 'code')->ignore($group->id),
            ],
            'description' => 'nullable|string|max:255',
            'organization_id' => 'nullable|exists:organizations,id',
            'is_active' => 'sometimes|boolean',
            'device_ids' => 'nullable|array',
            'device_ids.*' => 'integer|exists:devices,id',
            'personnel_ids' => 'nullable|array',
            'personnel_ids.*' => 'integer|exists:personnel,id',
            'department_ids' => 'nullable|array',
            'department_ids.*' => 'integer|exists:departments,id',
        ]);

        DB::transaction(function () use ($group, $validated, $request) {
            $group->update(collect($validated)->only(['name', 'code', 'description', 'organization_id', 'is_active'])->toArray());

            if ($request->has('device_ids')) {
                $group->devices()->sync($request->input('device_ids', []));
            }
            if ($request->has('personnel_ids')) {
                $group->personnel()->sync($request->input('personnel_ids', []));
            }
            if ($request->has('department_ids')) {
                $group->departments()->sync($request->input('department_ids', []));
            }
        });

        $group->load(['devices', 'personnel', 'departments', 'organization']);
        $group->loadCount(['devices', 'personnel', 'departments']);

        return response()->json([
            'success' => true,
            'message' => 'Access group updated successfully.',
            'data' => $group,
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $group = AccessGroup::findOrFail($id);

        DB::transaction(function () use ($group) {
            $group->devices()->detach();
            $group->personnel()->detach();
            $group->departments()->detach();
            $group->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'Access group deleted successfully.',
        ]);
    }

    public function syncNow(Request $request, $id): JsonResponse
    {
        $group = AccessGroup::with(['devices', 'personnel', 'departments'])->findOrFail($id);

        $devices = $group->devices()->where('is_active', true)->get();

        $directPersonnel = $group->personnel()->get();

        $deptIds = $group->departments()->pluck('departments.id');
        $deptPersonnel = collect();
        if ($deptIds->isNotEmpty()) {
            $deptPersonnel = Personnel::whereHas('employee', function ($q) use ($deptIds) {
                $q->whereIn('department_id', $deptIds);
            })->get();
        }

        $allPersonnel = $directPersonnel->merge($deptPersonnel)->unique('id');

        $dispatchedCount = 0;

        if (class_exists(AccessControlService::class) && method_exists(AccessControlService::class, 'syncZone')) {
            $result = app(AccessControlService::class)->syncZone($group);
            $dispatchedCount = $result['dispatched_jobs'] ?? 0;
        } else {
            foreach ($devices as $device) {
                foreach ($allPersonnel as $person) {
                    SyncDevicePersonnelJob::dispatch(
                        $device->id,
                        $person->id,
                        'ADD',
                        $person->customize_id
                    );
                    $dispatchedCount++;
                }
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Zone synchronization dispatched for {$devices->count()} device(s) and {$allPersonnel->count()} personnel member(s).",
            'devices_count' => $devices->count(),
            'personnel_count' => $allPersonnel->count(),
            'dispatched_count' => $dispatchedCount,
        ], 200);
    }
}
```

### 4.3 Proposed Frontend: `resources/js/components/settings/AccessGroupManager.vue`
A comprehensive, accessible Vue 3 component with:
- Search and status filters
- 5-row animated skeleton loader matching table geometry
- Interactive Assignment Modal for Devices, Departments, and Personnel
- SweetAlert2 confirmations via `notify.confirm(...)` for zone sync and deletion
- `SettingsHub.vue` tab registration:
  - Add `{ id: 'access-groups', label: 'Access Groups & Zones', icon: '🛡️' }` to `tabs` in `SettingsHub.vue`
  - Render `<AccessGroupManager v-else-if="activeTab === 'access-groups'" />`

---

## 5. Verification Method

### 5.1 Verification Commands
1. **Frontend Production Build**:
   ```bash
   npm run build
   ```
   *Expected outcome*: Vite compiles cleanly with exit code 0, verifying template syntax, imports, and reactive properties.

2. **Test `test_f11` (Access Group Zone Resync Endpoint)**:
   ```bash
   php artisan test --filter="Tier1FeatureCoverageTest::test_f11"
   ```
   *Expected outcome*: Test passes with 1 test, 1+ assertions.

3. **Test `test_f12` (Vue Component Physical Existence)**:
   ```bash
   php artisan test --filter="Tier1FeatureCoverageTest::test_f12"
   ```
   *Expected outcome*: Test passes with 1 test, 1 assertion.

4. **Test Cross-Feature Zone Resync (`Tier3CrossFeatureTest`)**:
   ```bash
   php artisan test --filter="Tier3CrossFeatureTest::test_cross_access_group_zone_resync"
   ```
   *Expected outcome*: Test passes with 1 test, 1+ assertions.

### 5.2 Invalidation Conditions
- Any route mismatch when normalized against `/api/access-groups/{id}/sync-now`.
- `syncNow` failing with HTTP 500 when an access group has 0 personnel attached.
- `AccessGroupManager.vue` missing from `resources/js/components/settings/`.
- Vite compilation errors during `npm run build`.
