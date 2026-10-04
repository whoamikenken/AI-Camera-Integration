# Adversarial Challenge Report — Milestone 1

**Target Milestone**: Milestone 1: Security Foundation, RBAC & Multi-Tenant Settings  
**Challenger**: Challenger 2 (Data Integrity, Concurrency & Security Boundaries)  
**Date**: 2026-09-30  
**Verdict**: **REQUEST_CHANGES**

---

## 1. Executive Summary

A comprehensive, white-box adversarial stress test suite (`tests/Feature/Challenger2AdversarialTest.php`) containing 20 discrete tests was designed, executed, and analyzed against Milestone 1 implementation.

While several security features proved robust (department cycle detection within the same organization, cache invalidation on rapid concurrent writes, and sensitive password/token filtering in audit logs), **six critical data integrity, isolation, and authorization defects** were empirically discovered and reproduced:

1. **Cross-Tenant Department Tree Injection (Store)**: An API consumer can attach a department in Organization B to a parent department in Organization A during creation (`POST /api/departments`).
2. **Cross-Tenant Department Tree Injection (Update)**: An API consumer can re-parent a department in Organization B to a department in Organization A via update (`PUT /api/departments/{id}`).
3. **Device Role Validation Bypassed**: `DeviceController` fails to validate `device_role`, accepting invalid values (e.g., `'invalid_role'`) with HTTP 200.
4. **Device Role & Multi-Tenant Field Persistence Omission**: `DeviceController::store` and `update` fail to persist `device_role`, `organization_id`, `location_id`, or `department_ids`. Valid roles such as `'entry'`, `'exit'`, `'visitor_kiosk'` are discarded and remain default `'bidirectional'`.
5. **Unenforced Route-Level RBAC on System Settings**: Unprivileged users with role `employee` can read, update, or wipe global settings via `PUT /api/settings` because `CheckPermission` middleware is not bound to settings routes.
6. **Unenforced Route-Level RBAC on Organizations**: Unprivileged employees can delete entire tenant organizations via `DELETE /api/organizations/{id}` because `CheckPermission` middleware is missing from organization routes.

---

## 2. Challenge Findings

### [Critical] Challenge 1: Multi-Tenant Department Tree Boundary Violation (Cross-Tenant Parent Injection)

- **Assumption Challenged**: Departments are strictly partitioned per tenant organization, preventing cross-tenant tree leakage.
- **Attack Scenario**:
  - Attacker creates or updates a department in Tenant B (`organization_id = 2`) specifying `parent_id` of Department 1 belonging to Tenant A (`organization_id = 1`).
  - `POST /api/departments` and `PUT /api/departments/{id}` validate only `'parent_id' => 'nullable|exists:departments,id'`.
  - The controller does not check whether `$parentDepartment->organization_id === $department->organization_id`.
- **Blast Radius**:
  - Department trees merge across tenant boundaries.
  - Calling `GET /api/departments/tree?organization_id=1` traverses and renders Tenant B's departments under Tenant A.
  - Calling `GET /api/departments?organization_id=2` exposes Tenant A's internal department IDs and hierarchy.
- **Mitigation**:
  In `OrganizationController::storeDepartment` and `updateDepartment`, add validation:
  ```php
  if (!empty($validated['parent_id'])) {
      $parent = Department::find($validated['parent_id']);
      if (!$parent || $parent->organization_id !== $orgId) {
          return response()->json([
              'success' => false,
              'message' => 'Parent department must belong to the same organization.',
          ], 422);
      }
  }
  ```

---

### [High] Challenge 2: Device Role Validation and Persistence Omission in Device API

- **Assumption Challenged**: Device roles (`entry`, `exit`, `bidirectional`, `visitor_kiosk`) and tenant associations (`organization_id`, `location_id`) are validated and persisted through `DeviceController`.
- **Attack Scenario**:
  - Client sends `PUT /api/devices/{id}` with `device_role => 'invalid_role_xyz'`. Expected: HTTP 422. Actual: HTTP 200 (validation bypassed).
  - Client sends `PUT /api/devices/{id}` with `device_role => 'entry'`. Expected: Database `devices.device_role` updated to `'entry'`. Actual: Database remains `'bidirectional'` because `$validated` in `DeviceController::update` does not include `device_role`.
  - Client creates/updates device with `organization_id` or `location_id`. Actual: Fields are stripped and discarded.
- **Blast Radius**:
  - Milestone 3 Biometric Attendance Processing relies directly on `devices.device_role` to pair entry/exit punches. Because the device API cannot set `device_role`, all devices are stuck as `'bidirectional'`.
  - Multi-tenant device scoping is broken because devices cannot be assigned to organizations or locations via API.
- **Mitigation**:
  In `DeviceController::store` and `update`:
  1. Add validation rules:
     ```php
     'organization_id' => 'nullable|exists:organizations,id',
     'location_id' => 'nullable|exists:locations,id',
     'device_role' => 'nullable|string|in:entry,exit,bidirectional,visitor_kiosk',
     'department_ids' => 'nullable|array',
     'department_ids.*' => 'integer|exists:departments,id',
     ```
  2. Include `$validated['device_role']`, `$validated['organization_id']`, `$validated['location_id']`, `$validated['department_ids']` in `$deviceData` and `$updateData`.
  3. Include `organization_id`, `location_id`, `device_role`, `department_ids` in `DeviceController::index` and `show` JSON output.

---

### [Critical] Challenge 3: RBAC Middleware Missing from Domain API Routes

- **Assumption Challenged**: Sensitive administrative endpoints are guarded by RBAC permissions defined in Milestone 1.
- **Attack Scenario**:
  - An authenticated user with only the `employee` role sends `PUT /api/settings` or `DELETE /api/organizations/{id}`.
  - Expected: HTTP 403 Forbidden.
  - Actual: HTTP 200 OK — settings mutated or organization deleted.
- **Root Cause**:
  `routes/api.php` registered `auth:sanctum` on lines 42-141, but did NOT attach `permission:...` middleware to domain routes. The `CheckPermission` middleware was created in `app/Http/Middleware/CheckPermission.php` and aliased in `bootstrap/app.php`, but never attached to routes.
- **Blast Radius**:
  Privilege escalation: Any low-privilege employee can reconfigure attendance rules, tamper with settings, or delete tenant organizations.
- **Mitigation**:
  Attach `permission:...` middleware to routes in `routes/api.php`:
  ```php
  Route::put('settings', [SettingController::class, 'update'])->middleware('permission:settings.manage');
  Route::apiResource('organizations', OrganizationController::class)->middleware('permission:org.manage');
  Route::apiResource('roles', RoleController::class)->middleware('permission:roles.manage');
  ```

---

## 3. Stress Test Results Matrix

| Domain | Scenario | Expected | Actual | Result |
|---|---|---|---|---|
| **Dept Hierarchy** | Self-Parenting (`PUT /api/departments/1` parent=1) | HTTP 422 | HTTP 422 | **PASS** |
| **Dept Hierarchy** | 2-Node Direct Loop (A -> B -> A) | HTTP 422 | HTTP 422 | **PASS** |
| **Dept Hierarchy** | 3-Node Indirect Loop (A -> B -> C -> A) | HTTP 422 | HTTP 422 | **PASS** |
| **Dept Hierarchy** | 5-Node Deep Cycle (D2 -> D3 -> D4 -> D5 -> D2) | HTTP 422 | HTTP 422 | **PASS** |
| **Dept Hierarchy** | Legitimate Hierarchy Move (re-parenting branch) | HTTP 200 | HTTP 200 | **PASS** |
| **Settings Cache** | Immediate Cache Invalidation on Update | Fresh read | Fresh read | **PASS** |
| **Settings Cache** | Tenant Override Isolation & Global Fallback | Strict isolation | Strict isolation | **PASS** |
| **Settings Cache** | Rapid Sequential Updates (30 iterations) | Zero stale reads | Zero stale reads | **PASS** |
| **Settings Cache** | API Bulk Update Invalidation (multi-key) | All keys fresh | All keys fresh | **PASS** |
| **Audit Trail** | Plaintext Password Leak on User Creation | Excluded from log | Excluded from log | **PASS** |
| **Audit Trail** | Password Hash Leak on User Password Update | Excluded from diff | Excluded from diff | **PASS** |
| **Audit Trail** | Remember Token Leak on Update | Excluded from diff | Excluded from diff | **PASS** |
| **Audit Trail** | Device Hardware Password Leak | Excluded from log | Excluded from log | **PASS** |
| **Multi-Tenancy** | Location Reassignment via API Update | Reassignment blocked | Reassignment blocked | **PASS** |
| **Multi-Tenancy** | Department Cross-Tenant Parent on Store | HTTP 422 | HTTP 201 (Created!) | **FAIL** |
| **Multi-Tenancy** | Department Cross-Tenant Parent on Update | HTTP 422 | HTTP 200 (Updated!) | **FAIL** |
| **Device Roles** | Invalid Device Role (`invalid_role`) | HTTP 422 | HTTP 200 (Bypassed) | **FAIL** |
| **Device Roles** | Valid Device Roles (`entry`, `exit`, etc.) Persist | DB updated | DB stays default | **FAIL** |
| **RBAC Routes** | Employee User Mutating Global Settings | HTTP 403 | HTTP 200 (Mutated!) | **FAIL** |
| **RBAC Routes** | Employee User Deleting Tenant Organization | HTTP 403 | HTTP 200 (Deleted!) | **FAIL** |

---

## 4. Unchallenged Areas

- **MQTT Streaming / Camera Hardware Telemetry (`MqttListenCommand`)**: Covered in foundation suite; hardware push remains unauthenticated by architecture design.
- **Milestone 2+ Features (Employees, Shifts, Punches, Leaves, Visitors)**: Opaque-box E2E test harness (`tests/Feature/E2E/`) properly skits these features awaiting their respective milestones.
