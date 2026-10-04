# Test Infrastructure & Specification (TEST_INFRA)

## Intelligent AI Camera Hub → Attendance & Visitor Management System

---

## 1. System Overview & Testing Philosophy

The **Intelligent AI Camera Hub** is undergoing a complete enterprise transformation into a multi-tenant **Attendance and Visitor Management System**. The system bridges physical AI edge camera hardware (X40Y and related IPCs running on LAN/WAN with HTTP/HTTPS Basic Auth and MQTT telemetry) with enterprise HR workflows, complex multi-shift scheduling, biometric punch pairing, leave management, visitor lifecycle tracking, real-time WebSocket telemetry, and payroll data generation.

The E2E testing strategy implements a **Requirement-Driven, Opaque-Box Multi-Tier Methodology** designed to ensure that every feature, boundary condition, cross-domain interaction, and enterprise lifecycle is authoritatively verified against the system specification without depending on internal implementation shortcuts or facade mocks.

---

## 2. Multi-Tier Testing Architecture

The test suite is structured into four primary requirement tiers plus a fifth adversarial hardening tier:

```
┌────────────────────────────────────────────────────────────────────────┐
│                   Tier 4: Real-World Workflows                        │
│   (Multi-step E2E lifecycles: Workday, Visitor, Leave, Security)       │
├────────────────────────────────────────────────────────────────────────┤
│                Tier 3: Cross-Feature Combinations                      │
│     (Pairwise domain interactions: Shift-Punch, Visitor-Camera Sync)    │
├────────────────────────────────────────────────────────────────────────┤
│                 Tier 2: Boundary & Corner Cases                        │
│   (Debounce windows, midnight shifts, grace limits, zero/negative)     │
├────────────────────────────────────────────────────────────────────────┤
│                   Tier 1: Feature Coverage                             │
│   (Isolated happy-path & contract verification across all 40 features) │
├────────────────────────────────────────────────────────────────────────┤
│                   Tier 5: Adversarial Hardening                        │
│       (White-box stress, concurrency, network dropouts, injections)    │
└────────────────────────────────────────────────────────────────────────┘
```

### Tier 1 — Feature Coverage
- **Purpose**: Verify isolated happy-path functionality and interface contracts for every feature in the `PROJECT.md` Feature Inventory (Features 1 through 40) plus existing Phase 0 camera integration capabilities.
- **Coverage**: Minimum 5 discrete test cases per functional domain.
- **Scope**: User authentication, RBAC, organization hierarchy, employee profiles, shift definitions, schedule assignments, holiday calendars, attendance punches, daily records, leave requests, visitor pre-registration, check-in/out, camera face synchronization, notifications, reports, and system settings.

### Tier 2 — Boundary & Corner Cases
- **Purpose**: Probe limits, edge times, invalid transitions, zero/negative values, and extreme inputs.
- **Coverage**: Minimum 5 edge-case tests per functional domain.
- **Scope**:
  - Debounce filtering (scans within 60s discarded vs >60s accepted).
  - Exact grace period cutoff (arrival at 09:15:00 on-time vs 09:15:01 late).
  - Midnight boundary crossing for overnight shifts (22:00 to 07:00).
  - Zero and negative leave quotas / hours.
  - Invalid state machine transitions (e.g. check out an un-checked-in visit, approve an already rejected request).
  - Maximum payload sizes, long strings, Unicode character preservation in names.
  - Non-working day and holiday exclusion in multi-day leave deductions.

### Tier 3 — Cross-Feature Combinations (Pairwise)
- **Purpose**: Verify state transitions and side effects across interacting subsystem boundaries.
- **Scope**:
  - **Employee Shift Change vs. Active Day**: Mid-cycle shift assignment changes and their effect on active daily attendance calculations.
  - **Visitor Check-In vs. Camera Hardware**: Check-in triggering temporary `personnel` row (`temp_valid: 1`) and `SyncPersonnelJob` on `camera-sync` queue.
  - **Approved Leave vs. Attendance Finalizer**: Leave approval overriding absence marking to `on_leave`.
  - **Watchlist vs. Biometric Detection**: Blocklisted visitor blocked at check-in (403) and flagged on camera detection.
  - **Holiday Calendar vs. Shift Schedule**: Holiday overriding scheduled working day and attributing hours to special overtime.
  - **Device Direction vs. Alternating Fallback**: Role-based direction (`entry`/`exit`) taking precedence over punch alternating logic.

### Tier 4 — Real-World Application Scenarios
- **Purpose**: Validate realistic, multi-step enterprise user lifecycles from start to finish.
- **Scope**:
  1. **Complete Workday Lifecycle**: Morning camera entry -> duplicate scan debounce -> midday lunch exit/entry -> evening exit -> net hours computation -> overtime calculation -> nightly finalizer -> monthly payroll export.
  2. **Complete Visitor Lifecycle**: Host pre-registers visitor -> Receptionist check-in wizard with photo -> Temporary camera face provisioning -> Camera entry verification -> Host arrival notification -> Visitor checkout -> Immediate camera face revocation (`DELETE` sync).
  3. **Leave Application & Regularization Cycle**: Employee submits leave request -> Manager approves -> Leave balance deducted -> Attendance record updated to `on_leave` -> Missing punch regularization submitted and approved -> Daily record recomputed.
  4. **Security Alert & Stranger Workflow**: Stranger face detected by camera -> Capture saved in `stranger_snaps` -> Receptionist converts stranger to visitor or security adds to watchlist -> Watchlisted attempt triggers immediate alert.
  5. **Overnight Shift Worker Workflow**: Worker assigned overnight shift (22:00 to 07:00 next day) -> Punches in at 21:55 -> Punches out at 07:15 -> Single daily record on shift start date with net work hours and no absence penalties.

---

## 3. Test Infrastructure & Environment Configuration

### Execution Engine & Framework
- **Test Runner**: PHPUnit 11.5.x executed via `php artisan test`.
- **Base Test Case**: `tests/Feature/E2E/E2ETestCase.php` (extending `Tests\TestCase`).
- **Database Strategy**: Fast, isolated in-memory SQLite database (`:memory:`) with `Illuminate\Foundation\Testing\RefreshDatabase`. Every test runs within an isolated transaction.
- **Queue & Async Execution**: Queue connection configured to `sync`, allowing queued jobs (`SyncPersonnelJob`, `ProcessAttendancePunchJob`, `DailyAttendanceFinalizerJob`, `ExpireVisitorAccessJob`) to run synchronously within test assertions.

### Edge Camera Hardware Simulation
- **LAN HTTP/HTTPS POST Mocking**: Simulated using `Http::fake()` to match the camera's proprietary `/action/*` protocol (`/action/EditPersonNew`, `/action/AddPersons`, `/action/DeletePerson`, `/action/SetMQTTParam`, `/action/RebootDevice`) returning standard camera response JSON:
  ```json
  { "code": 0, "message": "Success", "data": {} }
  ```
- **MQTT Telemetry Simulation**: Emulated by dispatching synthetic `AccessLogReceived`, `StrangerSnapReceived`, or invoking `MqttListenCommand` handlers with authoritative `VerifyPush`, `StrSnapPush`, and `HeartBeat` payloads.

### Time Simulation & Temporal Travel
- **Time Control**: Uses `Carbon\Carbon::setTestNow()` to freeze, advance, and travel across shift boundaries, grace periods, midnight crossings, and end-of-day finalization routines.

---

## 4. Progressive Testability & Milestone Integration Strategy

To allow parallel development between the **Implementation Track** (Milestones M1–M6) and the **E2E Testing Track**, the test harness adheres to **Progressive Testability**:
- Tests for completed foundation features (Phase 0: devices, personnel, sync tasks, access logs, stranger snaps, camera webhooks) execute and verify assertions immediately.
- Tests targeting features in upcoming milestones (M1 Auth/RBAC, M2 Employees/Shifts, M3 Attendance, M4 Leaves, M5 Visitors, M6 Reports) dynamically inspect migration and route availability:
  - If the required database table, route, or model has not yet been introduced by the active implementation milestone, the test gracefully marks itself as skipped with a descriptive message (e.g. `[Awaiting M2: employees table not yet migrated]`).
  - As soon as an implementation worker runs the migration or registers the routes, the test **automatically activates** and runs all assertions to green without modifying test code.
- When Milestone 7 is reached, all migrations and endpoints are present, and the entire suite executes with 100% pass rate.

---

## 5. Directory Layout

```
tests/
├── Feature/
│   ├── E2E/
│   │   ├── E2ETestCase.php                 # Base test harness, assertion helpers & mock factories
│   │   ├── Tier1FeatureCoverageTest.php    # 100+ isolated happy-path tests (Features 1-40)
│   │   ├── Tier2BoundaryTest.php           # 50+ boundary, limits, debounce & edge-time tests
│   │   ├── Tier3CrossFeatureTest.php       # 25+ pairwise domain interaction tests
│   │   └── Tier4RealWorldScenariosTest.php # 10+ end-to-end multi-step enterprise workflows
│   ├── CameraImportPersonnelTest.php       # Preserved foundation tests
│   ├── DeviceManagementTest.php            # Preserved foundation tests
│   ├── DeviceProbeAndHttpsTest.php         # Preserved foundation tests
│   ├── HistoricalBackfillTest.php          # Preserved foundation tests
│   ├── HttpProtocolV113Test.php            # Preserved foundation tests
│   └── PersonnelSyncTest.php               # Preserved foundation tests
├── Unit/
│   └── ExampleTest.php
├── TestCase.php
└── phpunit.xml
```

---

## 6. Test Runner Commands

### Run Full E2E Test Suite
```bash
php artisan test --filter=E2E
```

### Run by Specific Tier
```bash
# Tier 1: Feature Coverage
php artisan test --filter=Tier1FeatureCoverageTest

# Tier 2: Boundary & Corner Cases
php artisan test --filter=Tier2BoundaryTest

# Tier 3: Cross-Feature Interactions
php artisan test --filter=Tier3CrossFeatureTest

# Tier 4: Real-World Scenarios
php artisan test --filter=Tier4RealWorldScenariosTest
```

### Run by Milestone Domain
```bash
# Auth & RBAC (M1)
php artisan test --filter=test_m1

# Employees & Shifts (M2)
php artisan test --filter=test_m2

# Biometric Attendance Engine (M3)
php artisan test --filter=test_m3

# Leave Management (M4)
php artisan test --filter=test_m4

# Visitor Lifecycle (M5)
php artisan test --filter=test_m5

# Reports, Notifications & Payroll (M6)
php artisan test --filter=test_m6
```

---

## 7. Authoritative Output Derivation & Mathematical Formulas

Every test assertion is derived from the mathematical contracts established in `PROJECT.md` and `survey_spec_report.md`:

1. **Late Arrival Penalty**:
   $$\text{Grace Threshold} = \text{Shift Start} + \text{Grace Minutes}$$
   $$\text{If Arrival} > \text{Grace Threshold} \implies \text{Late Minutes} = \text{Arrival} - \text{Shift Start}$$
   *Penalty is calculated from Shift Start, not from Grace Cutoff.*

2. **Early Out Penalty**:
   $$\text{Early Threshold} = \text{Shift End} - \text{Early Out Threshold Minutes}$$
   $$\text{If Departure} < \text{Early Threshold} \implies \text{Early Minutes} = \text{Shift End} - \text{Departure}$$

3. **Net Work Hours**:
   $$\text{Gross Hours} = \frac{\text{Clock Out} - \text{Clock In}}{3600}$$
   $$\text{Total Net Hours} = \max(0, \text{Gross Hours} - \text{Break Hours})$$

4. **Overtime**:
   $$\text{Overtime Hours} = \max(0, \text{Total Net Hours} - \text{Min Hours Full Day})$$
   *(Subject to minimum overtime threshold setting).*

5. **Leave Balance Rollover**:
   $$\text{Unused Days} = \max(0, \text{Allocated} + \text{Carried Forward} - \text{Used})$$
   $$\text{New Carried Forward} = \min(\text{Unused Days}, \text{Max Carry Forward Limit})$$

---

## 8. Test Quality & Maintenance Rules

1. **Test Code Only**: Test writers create and modify test files only. Implementation defects must be documented and escalated to implementing agents.
2. **Deterministic Independence**: Every test method must instantiate its own state, use `RefreshDatabase`, and avoid depending on execution order.
3. **No Facade Passing**: Tests must perform real assertions against HTTP status codes, JSON response shapes, database state (`assertDatabaseHas`), or queued job payloads (`Queue::assertPushed`).
4. **Adversarial Integrity**: All user inputs, device names, and reason fields must be tested against special characters, HTML tags, and boundary values.
