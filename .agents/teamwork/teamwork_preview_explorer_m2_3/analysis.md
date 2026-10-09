# Milestone M2 Technical Exploration Report: Access Control Groups API & Frontend Management

**Agent**: `teamwork_preview_explorer_m2_3`  
**Date**: 2026-10-07  
**Scope**: Milestone M2 (Features 11 & 12) — API Routes, `AccessGroupController`, Frontend Component (`AccessGroupManager.vue`), Navigation Integration, and Test Coverage (`Tier1FeatureCoverageTest::test_f11`, `test_f12`).

---

## 1. Executive Summary

Milestone M2 introduces physical security segmentation and granular biometric provisioning through **Access Control Groups & Security Zones**. While earlier milestones treated all camera hardware as a monolithic broadcast pool (`Device::where('is_active', true)->get()`), Milestone M2 establishes discrete security perimeters (e.g. Server Rooms, Executive Suites, Research Labs, Warehouse Perimeter).

This technical exploration investigates the external and presentation interfaces for Milestone M2:
1. **API Layer**: Route declarations in `routes/api.php` and full controller implementation in `App\Http\Controllers\AccessGroupController.php` supporting search, pagination, full CRUD, many-to-many relationship management (`devices`, `personnel`, `departments`), and the zone resynchronization endpoint `POST /api/access-groups/{id}/sync-now`.
2. **Security & RBAC**: Verification of authentication guards (`auth:sanctum`, `active`) and permission middleware rules (`permission:devices.manage`, `permission:personnel.sync`).
3. **Frontend UI Component**: Design and architecture of `resources/js/components/settings/AccessGroupManager.vue`, including tabular roster, interactive assignment modal (devices, departments, personnel whitelist), zone resync trigger, WCAG 2.1 AA accessibility compliance, and embedding within `resources/js/components/settings/SettingsHub.vue`.
4. **Test Verification**: Contract analysis of `Tier1FeatureCoverageTest::test_f11`, `Tier1FeatureCoverageTest::test_f12`, and `Tier3CrossFeatureTest::test_cross_access_group_zone_resync_pushes_all_members_to_all_group_devices`.

---

## 2. API Routes & Endpoint Architecture

### 2.1 Route Registration Analysis (`routes/api.php`)

In `routes/api.php`, authenticated domain routes are grouped under:
```php
Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {
    // Domain routes
});
```

The Access Control Groups routes must be placed within this guarded group under the Devices/Hardware and Personnel domain blocks (around line 146).

#### Route Inventory:
| HTTP Verb | URI Path | Controller Action | Required Permissions | Description |
|---|---|---|---|---|
| `GET` | `/api/access-groups` | `AccessGroupController@index` | `devices.view,devices.manage,personnel.view` | List groups with pagination, search, and relationship counts |
| `POST` | `/api/access-groups` | `AccessGroupController@store` | `devices.manage` | Create access group and sync initial device/department/personnel relations |
| `GET` | `/api/access-groups/{id}` | `AccessGroupController@show` | `devices.view,devices.manage,personnel.view` | Retrieve single group with eager-loaded relations and member counts |
| `PUT` | `/api/access-groups/{id}` | `AccessGroupController@update` | `devices.manage` | Update group attributes and synchronize assigned pivots |
| `DELETE` | `/api/access-groups/{id}` | `AccessGroupController@destroy` | `devices.manage` | Detach associated pivots and remove group record |
| `POST` | `/api/access-groups/{id}/sync-now` | `AccessGroupController@syncNow` | `devices.manage,personnel.sync` | Dispatch face biometric synchronization for all group personnel to all group devices |

### 2.2 Route Registration Code Specification

```php
// routes/api.php (inside Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(...))

// Access Control Groups & Security Zones (Milestone M2)
Route::get('access-groups', [AccessGroupController::class, 'index'])
    ->middleware('permission:devices.view,devices.manage,personnel.view');
Route::post('access-groups', [AccessGroupController::class, 'store'])
    ->middleware('permission:devices.manage');
Route::get('access-groups/{id}', [AccessGroupController::class, 'show'])
    ->middleware('permission:devices.view,devices.manage,personnel.view');
Route::put('access-groups/{id}', [AccessGroupController::class, 'update'])
    ->middleware('permission:devices.manage');
Route::delete('access-groups/{id}', [AccessGroupController::class, 'destroy'])
    ->middleware('permission:devices.manage');
Route::post('access-groups/{id}/sync-now', [AccessGroupController::class, 'syncNow'])
    ->middleware('permission:devices.manage,personnel.sync');
```

---

## 3. Controller Implementation (`AccessGroupController.php`)

### 3.1 Design Principles
1. **Database Transactions**: All operations modifying the group and syncing multi-table pivots (`access_group_device`, `access_group_personnel`, `access_group_department`) execute within `DB::transaction()` to prevent partial assignment state.
2. **Resilient Parameter Resolution**: Method parameters accept either `$id` (integer or string) or Route-Model-Bound `AccessGroup` instances.
3. **Eager Loading & Aggregation**: Queries leverage `withCount(['devices', 'personnel', 'departments'])` to eliminate N+1 overhead in administrative lists.
4. **Zone Synchronization Protocol**: Resolves active devices in the zone (`$group->devices()->where('is_active', true)->get()`), aggregates direct personnel (`$group->personnel`) with departmental personnel (`Department -> Employee -> Personnel`), and dispatches device sync tasks cleanly.

### 3.2 Method Specifications

#### A. `index(Request $request): JsonResponse`
- **Query Parameters**:
  - `search` (string): case-insensitive search matching `name`, `code`, or `description`.
  - `is_active` (boolean): filter by active status.
  - `organization_id` (integer): filter by organization.
  - `per_page` (integer, default 15): pagination size.
  - `all` (boolean, default false): if true, bypasses pagination and returns full collection.
- **Response Format**:
  - Paginated: standard Laravel pagination envelope (`current_page`, `data: [...]`, `total`, `per_page`, `last_page`).
  - All: `{ "success": true, "data": [...] }`.

#### B. `store(Request $request): JsonResponse`
- **Validation Rules**:
  ```php
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
  ```
- **Return**: HTTP 201 with created record and loaded relations.

#### C. `show($id): JsonResponse`
- Eager loads `devices`, `personnel`, `departments`, `organization` and counts.
- **Return**: HTTP 200 with `{ "success": true, "data": $accessGroup }`.

#### D. `update(Request $request, $id): JsonResponse`
- **Validation Rules**:
  - `code` validation ignores the current record ID:
    `Rule::unique('access_groups', 'code')->ignore($group->id)`.
  - Relation arrays (`device_ids`, `personnel_ids`, `department_ids`) synced only when present in request payload.
- **Return**: HTTP 200 with `{ "success": true, "data": $freshGroup }`.

#### E. `destroy($id): JsonResponse`
- Detaches pivots from `devices`, `personnel`, and `departments`.
- Deletes the `AccessGroup` model.
- **Return**: HTTP 200 `{ "success": true, "message": "Access group deleted successfully." }`.

#### F. `syncNow(Request $request, $id): JsonResponse`
- **Core Workflow**:
  1. Retrieve `AccessGroup` by ID with relations.
  2. Query active edge devices: `$devices = $group->devices()->where('is_active', true)->get();`.
  3. Query direct personnel: `$directPersonnel = $group->personnel()->get();`.
  4. Query departmental personnel:
     ```php
     $deptIds = $group->departments()->pluck('departments.id');
     $deptPersonnel = collect();
     if ($deptIds->isNotEmpty()) {
         $deptPersonnel = Personnel::whereHas('employee', function ($q) use ($deptIds) {
             $q->whereIn('department_id', $deptIds);
         })->get();
     }
     $allPersonnel = $directPersonnel->merge($deptPersonnel)->unique('id');
     ```
  5. Dispatch synchronization jobs:
     - If `AccessControlService::syncZone` exists, delegate to service:
       `$res = app(AccessControlService::class)->syncZone($group);`
     - Otherwise, iterate over all devices and personnel, dispatching `SyncDevicePersonnelJob`:
       ```php
       foreach ($devices as $device) {
           foreach ($allPersonnel as $person) {
               SyncDevicePersonnelJob::dispatch($device->id, $person->id, 'ADD', $person->customize_id);
               $dispatchedCount++;
           }
       }
       ```
  6. Return HTTP 200:
     ```php
     return response()->json([
         'success' => true,
         'message' => "Zone synchronization dispatched for {$devices->count()} device(s) and {$allPersonnel->count()} personnel member(s).",
         'devices_count' => $devices->count(),
         'personnel_count' => $allPersonnel->count(),
         'dispatched_count' => $dispatchedCount,
     ], 200);
     ```
  - **Graceful Zero-State Handling**: If group has 0 devices or 0 members (as tested in `Tier1FeatureCoverageTest::test_f11`), the loop does not run, no exception is thrown, and HTTP 200 is returned immediately.

---

## 4. Frontend Component Architecture (`AccessGroupManager.vue`)

### 4.1 File Location & Navigation Wiring
- **Component File**: `resources/js/components/settings/AccessGroupManager.vue`
- **Parent Hub**: `resources/js/components/settings/SettingsHub.vue`
- **Wiring in `SettingsHub.vue`**:
  ```javascript
  // resources/js/components/settings/SettingsHub.vue
  import AccessGroupManager from './AccessGroupManager.vue';

  const tabs = [
    { id: 'departments', label: 'Organization & Departments', icon: '🏢' },
    { id: 'access-groups', label: 'Access Groups & Zones', icon: '🛡️' },
    { id: 'system', label: 'System Parameters', icon: '⚙️' },
    { id: 'audit', label: 'Audit Trail', icon: '📋' },
  ];
  ```
  ```html
  <div>
    <DepartmentManager v-if="activeTab === 'departments'" />
    <AccessGroupManager v-else-if="activeTab === 'access-groups'" />
    <SystemSettings v-else-if="activeTab === 'system'" />
    <AuditLogViewer v-else-if="activeTab === 'audit'" />
  </div>
  ```

### 4.2 Component Feature Breakdown

1. **Toolbar & Filter Header**:
   - Title: "Access Control Groups & Security Zones"
   - Subtitle: "Segment camera hardware targets by physical security perimeters, department boundaries, and personnel whitelists."
   - Search input with `aria-label="Search access groups by name or code"`
   - Filter dropdown (All Statuses, Active Only, Inactive Only) with `aria-label="Filter access groups by status"`
   - Action buttons: "➕ New Access Group" and "🔄 Refresh"

2. **Access Groups Roster Table**:
   - `scope="col"` on all 8 table headers (`Code`, `Group Name`, `Assigned Cameras`, `Departments`, `Personnel Whitelist`, `Status`, `Actions`).
   - 5-row responsive skeleton table loader with `animate-pulse motion-reduce:animate-none` to eliminate Cumulative Layout Shift (CLS).
   - Empty state card with call-to-action button when 0 groups are found.
   - Target devices rendered with badge chips and online status indicators.
   - Action buttons per row:
     - `🔄 Sync Zone`: Triggers immediate hardware biometric synchronization for all group personnel to all group devices.
     - `✏️ Edit`: Opens full assignment modal with pre-populated values.
     - `🗑️ Delete`: Confirms via `notify.confirm(...)` and removes group.

3. **Assignment Matrix Modal (`AccessGroupModal`)**:
   - Semantic dialog markup: `role="dialog"`, `aria-modal="true"`, `aria-labelledby="access-group-modal-title"`, `@keydown.escape="closeModal"`.
   - Accessible label associations for all inputs (`<label for="group_name">` linked to `<input id="group_name">`).
   - Tabbed / Sectioned controls:
     - **Basic Information**: Name, Code, Description, Is Active toggle.
     - **Assigned Devices Selector**: Multi-select checkbox grid listing registered cameras with IP address, device ID, and online/offline status badge.
     - **Assigned Departments Selector**: Multi-select checkbox grid listing organizational departments (auto-grants all employees belonging to selected departments).
     - **Direct Personnel Whitelist**: Searchable multi-select picker for individual employee/personnel additions and exceptions.
   - Save button with asynchronous spinner state (`isSaving`).

4. **Zone Synchronization UX**:
   - Clicking "Sync Zone" triggers `notify.confirm(...)`:
     ```javascript
     const confirmed = await notify.confirm(
       'Synchronize Security Zone',
       `Push full biometric face roster for "${group.name}" to all ${group.devices_count || 0} assigned edge camera devices?`,
       'Yes, Synchronize Zone'
     );
     ```
   - On confirmation, executes `POST /api/access-groups/${group.id}/sync-now`.
   - On HTTP 200, displays success notification: `notify.toast('Zone biometric sync task dispatched successfully.')`.

---

## 5. Test Suite Verification & Contract Analysis

### 5.1 Analysis of `Tier1FeatureCoverageTest::test_f11`
```php
public function test_f11_access_group_zone_resync_endpoint_dispatches_roster(): void
{
    $this->requireRoute('/api/access-groups/1/sync-now', 'POST', 'Milestone 2');
    $this->requireTable('access_groups', 'Milestone 2');

    $this->actingAsAdmin();
    $group = \App\Models\AccessGroup::create([
        'id' => 1,
        'name' => 'Zone Sync Test',
        'code' => 'ZONE-SYNC-TEST',
        'is_active' => true,
    ]);
    $device = $this->createTestDevice();
    $group->devices()->attach($device->id);

    $response = $this->postJson('/api/access-groups/' . $group->id . '/sync-now');
    $response->assertStatus(200);
}
```
- **Preconditions**:
  1. Route `POST /api/access-groups/{id}/sync-now` registered in `routes/api.php`.
  2. Table `access_groups` and pivot `access_group_device` migrated.
  3. User authenticated via `actingAsAdmin()` (role `super-admin`).
- **Input State**: Group #1 with 1 device attached, 0 personnel attached.
- **Expectation**: HTTP 200 status returned without error.
- **Verification Guarantee**: Handled cleanly by our `syncNow` controller logic.

### 5.2 Analysis of `Tier1FeatureCoverageTest::test_f12`
```php
public function test_f12_access_group_manager_vue_component_exists(): void
{
    $this->requireFile('resources/js/components/settings/AccessGroupManager.vue', 'Milestone 2');
    $this->assertFileExists(base_path('resources/js/components/settings/AccessGroupManager.vue'));
}
```
- **Expectation**: The file `resources/js/components/settings/AccessGroupManager.vue` must physically exist in the filesystem.
- **Verification Guarantee**: Directly satisfied when the component is created.

### 5.3 Analysis of `Tier3CrossFeatureTest::test_cross_access_group_zone_resync_pushes_all_members_to_all_group_devices`
```php
public function test_cross_access_group_zone_resync_pushes_all_members_to_all_group_devices(): void
{
    $this->requireRoute('/api/access-groups/1/sync-now', 'POST', 'Milestone 2');
    $this->requireTable('access_groups', 'Milestone 2');

    $this->actingAsAdmin();
    $cam = $this->createTestDevice();
    $personnel = $this->createTestPersonnel();

    $group = \App\Models\AccessGroup::create([
        'id' => 1,
        'name' => 'Resync Campus',
        'code' => 'RESYNC-CAMPUS',
        'is_active' => true,
    ]);
    $group->devices()->attach($cam->id);
    $group->personnel()->attach($personnel->id);

    $response = $this->postJson('/api/access-groups/' . $group->id . '/sync-now');
    $response->assertStatus(200);
}
```
- **Input State**: Group #1 with 1 device attached and 1 personnel attached.
- **Expectation**: HTTP 200 status returned.
- **Verification Guarantee**: Handled cleanly with `SyncDevicePersonnelJob` dispatched for the camera and personnel.

---

## 6. Actionable Implementation Checklist

1. [ ] **API Controller**: Create `app/Http/Controllers/AccessGroupController.php` with `index`, `store`, `show`, `update`, `destroy`, and `syncNow`.
2. [ ] **API Routes**: Add route declarations in `routes/api.php` under authenticated middleware with appropriate permissions (`permission:devices.manage`, `permission:personnel.sync`).
3. [ ] **Frontend Component**: Create `resources/js/components/settings/AccessGroupManager.vue` with accessible table, assignment modal, skeleton loader, and SweetAlert2 confirmation.
4. [ ] **Navigation Integration**: Update `resources/js/components/settings/SettingsHub.vue` to add the `access-groups` tab and render `<AccessGroupManager />`.
5. [ ] **Automated Verification**:
   - `npm run build`: compile Vite assets to confirm clean Vue 3 syntax and zero bundle errors.
   - `php artisan test --filter=Tier1FeatureCoverageTest::test_f11`: verify resync endpoint passes.
   - `php artisan test --filter=Tier1FeatureCoverageTest::test_f12`: verify Vue component existence assertion passes.
   - `php artisan test --filter=Tier3CrossFeatureTest::test_cross_access_group_zone_resync`: verify cross-feature zone resync passes.
