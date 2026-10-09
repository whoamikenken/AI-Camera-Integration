# Test Infrastructure & Specification (TEST_INFRA)

## Intelligent AI Camera Hub — Enterprise Evolution

---

## 1. System Overview & Testing Philosophy

The **Intelligent AI Camera Hub** is an enterprise biometric access control, attendance calculation, and security vision telemetry platform. The system operates on a Pure WAN MQTT Architecture interfacing with AI edge camera devices (X40Y IPCs) across WAN networks, bridging physical access control with enterprise workforce management.

The platform evolution introduces:
1. **Testing Foundations & Gateway Decoupling**: Expressive Eloquent model factories and `CameraGatewayInterface` abstractions, replacing `app()->environment('testing')` conditionals.
2. **Access Control Groups & Zone-Based Dispatching**: Domain entities (`access_groups`, pivots) scoping biometric synchronization strictly to authorized devices.
3. **Resilient Domain Lifecycle State Machines**: Comprehensive cancellation flows for leaves (with balance and attendance rollback), regularizations, and automated visitor lifecycle tracking (`DetectOverstayVisitorsJob`, `ExpireNoShowVisitsJob`).
4. **Bulk Workforce Operations & Fleet Provisioning Campaigns**: Asynchronous batch campaigns (`bulk_campaigns`) for fleet reboot, MQTT parameter updates, and high-throughput face synchronization (`AddPersons` up to 50 persons per packet).
5. **Two-Tier Telemetry Ingestion & Downlink Command Correlator**: Decoupled zero-latency MQTT listener (`PushAck` <2ms) offloading to Redis queue `camera-telemetry` processed by `ProcessTelemetryPacketJob`, and non-blocking downlink command tickets (`device_commands`, `202 Accepted`) correlated by `messageId`.
6. **API Response Uniformity & Frontend Composables**: Standardized `ApiResponse` envelope, dedicated Form Requests, Dedoc Scramble OpenAPI documentation at `/docs/api`, and reusable Vue 3 composables (`usePaginatedResource`, `useLiveTelemetryStream`, `useBiometricCapture`).
7. **E2E Verification & Adversarial Hardening**: Complete automated verification (`php artisan test`, `npm run build`) across multi-tier test suites.

### Testing Philosophy: Opaque-Box, Requirement-Driven
- **Opaque-Box Verification**: Tests interact with the system strictly through defined interface contracts, HTTP APIs, MQTT event streams, and database states. Tests verify *what* the system does against documented requirements, never *how* internal private methods execute.
- **Authoritative Derivation**: Expected outputs are derived mathematically from domain specifications (`system-evo.md`, `PROJECT.md`, `survey_spec_report.md`), including grace periods, chunk partitions (50-person batches), balance transaction restorations, and correlation message IDs.
- **Progressive Testability**: The E2E suite executes cleanly at any stage of milestone progression. Tests targeting upcoming milestone features inspect schema, routes, or class existence dynamically (`requireTable`, `requireRoute`, `requireClass`, `requireFile`), automatically activating as implementation workers complete milestones.

---

## 2. Multi-Tier Testing Architecture

```
┌────────────────────────────────────────────────────────────────────────┐
│                   Tier 4: Real-World Workflows                        │
│   (Campus zones, emergency leave cancel, bulk onboarding, overstay)   │
├────────────────────────────────────────────────────────────────────────┤
│                Tier 3: Cross-Feature Combinations                      │
│     (Pairwise: Zone sync + telemetry, Leave cancel + attendance)      │
├────────────────────────────────────────────────────────────────────────┤
│                 Tier 2: Boundary & Corner Cases                        │
│     (50-person chunk boundary, 15m overstay, midnight, 0-members)     │
├────────────────────────────────────────────────────────────────────────┤
│                   Tier 1: Feature Coverage                             │
│     (Isolated equivalence-class tests across all 43 features)          │
├────────────────────────────────────────────────────────────────────────┤
│                   Tier 5: Adversarial Hardening                        │
│       (SSRF injection, hardware timeouts, network packet storms)      │
└────────────────────────────────────────────────────────────────────────┘
```

### Tier 1 — Feature Coverage
- **Purpose**: Verify isolated functionality and interface contracts for all 43 features in `PROJECT.md`.
- **Target**: Equivalence class representative tests verifying each feature in isolation.
- **Coverage**: Features 1 through 43 spanning Milestones M1 through M7.

### Tier 2 — Boundary & Corner Cases
- **Purpose**: Probe limits, empty inputs, extreme durations, invalid states, and max-size chunks.
- **Coverage**:
  - Chunk boundaries: exact 50-person batch partitions (50 items -> 1 chunk; 51 items -> 2 chunks: 50 + 1).
  - Empty selections: 0 devices or 0 personnel submitted to bulk endpoints (422 validation).
  - Time boundaries: exact 15-minute overstay threshold (14m59s normal vs 15m01s overstayed).
  - Midnight boundary crossing for `ExpireNoShowVisitsJob`.
  - Zero access groups fallback mode: system with 0 access groups retains legacy broadcast to all active devices.
  - Partial batch failure: offline cameras marked failed without stalling fleet campaigns.

### Tier 3 — Cross-Feature Combinations (Pairwise)
- **Purpose**: Verify state transitions and side effects across interacting subsystem boundaries.
- **Scope**:
  - **Access Control + Telemetry / Punch Processing**: Face synchronization only pushes to authorized devices in the person's access group; biometric verification events from authorized cameras process cleanly into attendance punches.
  - **Leave Cancellation + Attendance Recalculation**: Cancelling an approved leave restores balance atomically AND triggers `AttendanceProcessingService::processDay` to recalculate attendance from raw punches.
  - **Visitor Overstay + Security Alerts**: Active visit exceeding expected departure triggers `DetectOverstayVisitorsJob`, transitioning visit status to `overstayed` and creating a linked `DeviceAlert`.
  - **Bulk Campaigns + Downlink Correlation**: Bulk maintenance campaign creates `BulkCampaign` record, dispatches asynchronous `DeviceCommand` tickets with unique `messageId`s, and correlates incoming hardware ACKs to increment campaign progress.
  - **Visitor Cancellation + Hardware Face Revocation**: Cancelling a checked-in visit immediately dispatches `DELETE` face de-provisioning to edge cameras.

### Tier 4 — Real-World Application Scenarios
- **Purpose**: Validate multi-step, end-to-end enterprise user lifecycles.
- **Scope**:
  1. **Multi-Building Facility with Access Zones**: Campus with Engineering and Executive buildings; employee assigned to Engineering access group; verified only on Engineering cameras; promotion triggers diff sync to Executive cameras.
  2. **Emergency Shift with Leave Cancellation**: Employee on approved leave called for emergency shift; punches recorded; leave cancelled; balance restored and attendance record recomputed to present with overtime.
  3. **Large Workforce Bulk Onboarding & Fleet Maintenance**: HR onboards 120 employees partitioned into [50, 50, 20] chunks; fleet administrator executes bulk reboot; campaigns track progress asynchronously to 100%.
  4. **Complete Visitor Lifecycle with Overstay & Revocation**: Pre-registration -> check-in with photo sync -> overstay detection after delayed departure -> security desk alert -> checkout with physical face revocation.
  5. **High-Throughput Telemetry Burst & Downlink Ticket Correlation**: High-volume punch burst processed by two-tier ingestion (`PushAck` <2ms + Redis queue); concurrent parameter downlink returns `202 Accepted` ticket, resolved by incoming ACK.

---

## 3. Comprehensive Feature Inventory (43 Features)

| # | Feature | Milestone | Description | Tier 1 Test Method | Tier 2/3/4 Cross-Coverage |
|---|---------|-----------|-------------|-------------------|--------------------------|
| 1 | Comprehensive Eloquent Model Factories | M1 | 10 factories with expressive states (Device, Personnel, Employee, Shift, Punch, Visitor, Visit, Leave, Department, Location) | `test_f01_eloquent_factories_create_valid_domain_instances` | T2 Factory states; T4 Workflows |
| 2 | Camera Gateway Abstraction | M1 | `CameraGatewayInterface`, `MqttCameraGateway`, `HttpCameraGateway` | `test_f02_camera_gateway_interface_and_implementations_exist` | T3 Zone sync dispatch; T4 Downlink |
| 3 | Fluent Mocking Camera Gateway | M1 | `FakeCameraGateway` with fluent assertions (`CameraGateway::fake()`) | `test_f03_fake_camera_gateway_intercepts_commands_with_fluent_assertions` | T1-T4 Mocking edge hardware |
| 4 | Removal of Production Testing Conditionals | M1 | Eliminate all 4 `app()->environment('testing')` blocks in `CameraMqttService` | `test_f04_camera_mqtt_service_has_no_testing_environment_conditionals` | T3 Production code cleanliness |
| 5 | Access Group Entity (`access_groups`) | M2 | Entity defining organizational security zones | `test_f05_access_group_entity_persists_with_code_uniqueness` | T2 Special characters; T3 Group scoping |
| 6 | Group-to-Device Pivot (`access_group_device`) | M2 | Pivot linking access groups to edge camera devices | `test_f06_access_group_device_pivot_links_hardware` | T3 Device resolution; T4 Multi-zone |
| 7 | Group-to-Personnel Pivot (`access_group_personnel`) | M2 | Pivot linking specific personnel to access groups | `test_f07_access_group_personnel_pivot_links_individuals` | T3 Member assignment; T4 Onboarding |
| 8 | Group-to-Department Pivot (`access_group_department`) | M2 | Pivot granting zone access based on department membership | `test_f08_access_group_department_pivot_auto_grants_access` | T3 Department inheritance |
| 9 | Target Device Resolution Service | M2 | `AccessControlService::getAuthorizedDevicesForPersonnel()` | `test_f09_access_control_service_resolves_authorized_devices` | T2 Overlapping groups; T3 Pairwise |
| 10 | Zone-Scoped Personnel Sync | M2 | `SyncPersonnelJob` scopes sync commands strictly to authorized devices | `test_f10_sync_personnel_job_dispatches_only_to_authorized_devices` | T3 Access control + telemetry; T4 Campus |
| 11 | Access Group Zone Re-sync Endpoint | M2 | `POST /api/access-groups/{id}/sync-now` | `test_f11_access_group_zone_resync_endpoint_dispatches_roster` | T2 0-device group; T3 Re-sync |
| 12 | Access Group Manager UI | M2 | `AccessGroupManager.vue` in Settings Hub | `test_f12_access_group_manager_vue_component_exists` | T4 Settings UI |
| 13 | Leave Request Cancellation Workflow | M3 | `cancelLeaveRequest` in `LeaveService` with atomic balance restoration | `test_f13_leave_request_cancellation_restores_balance_atomically` | T2 0.5-day cancel; T3 Leave + attendance |
| 14 | Attendance Status Rollback & Recalculation | M3 | Rollback of 'on_leave' attendance records upon leave cancellation | `test_f14_attendance_status_rollback_and_recalculation_on_leave_cancel` | T3 Recalculation; T4 Emergency shift |
| 15 | Regularization Cancellation Workflow | M3 | Cancellation of unapproved regularization requests via API | `test_f15_regularization_cancellation_workflow_updates_status` | T2 Reject approved cancel |
| 16 | Visitor Cancellation & Face Revocation | M3 | Cancellation of visits with immediate edge camera face de-provisioning | `test_f16_visitor_cancellation_revokes_camera_face_credentials` | T3 Physical revocation; T4 Visitor E2E |
| 17 | Overstayed Visitor Detection Job | M3 | Scheduled job `DetectOverstayVisitorsJob` (every 15m) with `DeviceAlert` | `test_f17_detect_overstay_visitors_job_flags_overstay_and_creates_alert` | T2 15m cutoff; T3 Overstay + alerts |
| 18 | No-Show Visit Expiration Job | M3 | Scheduled job `ExpireNoShowVisitsJob` (midnight) expiring past visits | `test_f18_expire_no_show_visits_job_transitions_past_visits` | T2 Midnight cutoff |
| 19 | Overstayed Visits Endpoint | M3 | `GET /api/visits/overstayed` | `test_f19_overstayed_visits_endpoint_returns_flagged_roster` | T3 Security monitor |
| 20 | Bulk Campaigns Tracking Entity | M4 | Migration and `BulkCampaign` model tracking asynchronous batch tasks | `test_f20_bulk_campaigns_entity_tracks_execution_progress` | T2 Large campaigns; T3 Batch tracking |
| 21 | Fleet Bulk Reboot Endpoint & Job | M4 | Asynchronous batch reboot across selected cameras with rate limiting | `test_f21_fleet_bulk_reboot_endpoint_dispatches_rate_limited_jobs` | T2 Partial failures; T4 Fleet maintenance |
| 22 | Fleet Bulk MQTT Sync Endpoint & Job | M4 | Asynchronous batch MQTT parameter updates (`UpMQTTconfig`) | `test_f22_fleet_bulk_mqtt_sync_endpoint_dispatches_parameter_updates` | T2 Config validation |
| 23 | High-Throughput Bulk Personnel Sync | M4 | Batching face records into `AddPersons` (up to 50 persons per packet) | `test_f23_high_throughput_bulk_personnel_sync_batches_up_to_50_persons` | T2 [50, 50, 35] partition; T4 Onboarding |
| 24 | Bulk Personnel Deletion Endpoint & Job | M4 | Batch deletion across personnel and edge cameras | `test_f24_bulk_personnel_deletion_endpoint_removes_records_and_dispatches` | T2 Empty selection rejection |
| 25 | Bulk Campaign Progress API | M4 | `GET /api/bulk-campaigns/{id}` returning execution progress | `test_f25_bulk_campaign_progress_api_returns_status_and_counters` | T2 Real-time progress |
| 26 | Fleet & Personnel Batch Toolbars | M4 | Batch action toolbars in `DeviceManager.vue` & `PersonnelManager.vue` | `test_f26_fleet_and_personnel_batch_toolbars_exist_in_frontend` | T4 UI batch triggers |
| 27 | Zero-Latency Telemetry Ingestion (Tier 1) | M5 | `MqttListenCommand` immediate `PushAck` (<2ms) and enqueue to Redis | `test_f27_zero_latency_telemetry_ingestion_issues_immediate_push_ack` | T2 Rapid bursts; T4 Ingestion pipeline |
| 28 | Asynchronous Telemetry Worker (Tier 2) | M5 | `ProcessTelemetryPacketJob` on Redis queue `camera-telemetry` | `test_f28_asynchronous_telemetry_worker_processes_packet_and_persists` | T3 Ingestion to punch pairing |
| 29 | Horizon Configuration for Telemetry | M5 | Configure supervisor queue `camera-telemetry` in `config/horizon.php` | `test_f29_horizon_configuration_monitors_camera_telemetry_queue` | T5 Concurrency scaling |
| 30 | Downlink Command Table & Model | M5 | Migration `device_commands` and model `DeviceCommand` | `test_f30_downlink_command_table_and_model_persist_tickets` | T2 Command states; T3 Correlator |
| 31 | Non-blocking Downlink Ticket Dispatch | M5 | `CameraMqttService::dispatchCommandAsync` & `202 Accepted` response | `test_f31_non_blocking_downlink_ticket_dispatch_returns_202_accepted` | T3 Async correlator; T4 Fleet downlink |
| 32 | Hardware ACK Correlation | M5 | Correlation in `MqttListenCommand` by `messageId` updating tickets | `test_f32_hardware_ack_correlation_matches_ticket_by_message_id` | T2 Corrupted messageId; T3 Pairwise |
| 33 | Downlink Event Broadcasting | M5 | Broadcast `DeviceCommandCompleted` via Laravel Reverb | `test_f33_downlink_event_broadcasting_emits_command_completed_event` | T4 Live UI ticket updates |
| 34 | Standard API Response Envelope | M6 | `ApiResponse` helper with dual-compatibility for tests & legacy keys | `test_f34_standard_api_response_envelope_formats_success_and_errors` | T2 Empty paginated meta |
| 35 | Hardware Webhook Protocol Exemption | M6 | Strict protocol preservation on `/Subscribe/*` for edge firmware | `test_f35_hardware_webhook_protocol_exemption_preserves_edge_firmware_format` | T5 Firmware compatibility |
| 36 | Dedicated Form Requests | M6 | 24 Form Request classes across Employee, Device, Shift, Visitor, Leave | `test_f36_dedicated_form_requests_validate_domain_payloads` | T2 422 validation errors |
| 37 | Automated OpenAPI Documentation | M6 | Dedoc Scramble integration at `/docs/api` with Bearer token security | `test_f37_automated_openapi_documentation_configured_at_docs_api` | T4 API exploration |
| 38 | Universal Paginated Resource Composable | M6 | `usePaginatedResource.js` with debounce and universal parser | `test_f38_universal_paginated_resource_composable_exists` | T4 Frontend pagination |
| 39 | Live Telemetry Stream Composable | M6 | `useLiveTelemetryStream.js` with private Echo channel & chime audio | `test_f39_live_telemetry_stream_composable_exists` | T4 Real-time dashboard |
| 40 | Biometric Capture Composable | M6 | `useBiometricCapture.js` with aspect ratio 1:1 square crop | `test_f40_biometric_capture_composable_exists` | T4 Photo enrollment |
| 41 | Frontend View Refactoring | M6 | Refactor 7 views to consume the composables | `test_f41_frontend_views_refactored_to_consume_composables` | T4 UI consistency |
| 42 | E2E Testing Suite Pass (Tiers 1-4) | M7 | Pass 100% of requirement-driven E2E tests | `test_f42_e2e_testing_suite_passes_across_all_tiers` | Overall suite readiness |
| 43 | Adversarial Coverage Hardening (Tier 5) | M7 | White-box stress tests, edge case validation, and clean audit | `test_f43_adversarial_security_and_edge_case_hardening_passes` | Security & stability gates |

---

## 4. Test Infrastructure & Execution Commands

### Base Test Harness: `tests/Feature/E2E/E2ETestCase.php`
- Extends Laravel `Tests\TestCase`.
- Uses `Illuminate\Foundation\Testing\RefreshDatabase` for transactional test isolation.
- Helper methods for progressive testability:
  - `requireTable(string $table, string $milestone)`
  - `requireRoute(string $uri, string $method, string $milestone)`
  - `requireClass(string $className, string $milestone)`
  - `requireMethod(string $className, string $methodName, string $milestone)`
  - `requireFile(string $relativePath, string $milestone)`

### Test Execution Commands

```bash
# Run entire E2E test suite
php artisan test --filter=E2E

# Run Tier 1: Feature Coverage (Isolated tests across all 43 features)
php artisan test --filter=Tier1FeatureCoverageTest

# Run Tier 2: Boundary & Corner Cases
php artisan test --filter=Tier2BoundaryTest

# Run Tier 3: Cross-Feature Interactions
php artisan test --filter=Tier3CrossFeatureTest

# Run Tier 4: Real-World Scenarios
php artisan test --filter=Tier4RealWorldScenariosTest

# Run by Milestone Scope
php artisan test --filter=test_m1_    # Testing Harness & Factories (M1)
php artisan test --filter=test_m2_    # Access Groups & Zones (M2)
php artisan test --filter=test_m3_    # Domain State Machines (M3)
php artisan test --filter=test_m4_    # Bulk Fleet Operations (M4)
php artisan test --filter=test_m5_    # Two-Tier Telemetry & Downlink (M5)
php artisan test --filter=test_m6_    # API Uniformity & Composables (M6)
php artisan test --filter=test_m7_    # Verification & Hardening (M7)
```

---

## 5. Authoritative Output Derivation & Mathematical Rules

1. **High-Throughput Batching**:
   $$\text{Total Packets} = \left\lceil \frac{N}{50} \right\rceil$$
   For $N = 135$ personnel records, partitions are strictly $[50, 50, 35]$.

2. **Visitor Overstay Evaluation**:
   $$\text{Is Overstayed} = (\text{Status} = \text{'checked\_in'}) \land (\text{now}() > \text{Expected Departure})$$
   Alert deduplication condition: $\text{overstay\_alerted\_at} \text{ IS NULL}$.

3. **Leave Balance Reversal**:
   - Cancel from `pending`: $\text{pending} = \max(0, \text{pending} - \text{days})$
   - Cancel from `approved`: $\text{used} = \max(0, \text{used} - \text{days})$
   - Executed inside atomic database transaction with `lockForUpdate()`.

4. **Telemetry Ingestion Response Timing**:
   $$\text{PushAck Latency} \le 2\text{ms}$$
   MQTT daemon returns `PushAck` synchronously and delegates image decoding/PostgreSQL insertion to Redis queue `camera-telemetry`.

5. **Downlink Correlator Ticket State Flow**:
   $$\text{Ticket State}: \text{pending} \xrightarrow{\text{ACK packet on } \texttt{*-Ack}} \begin{cases} \text{completed} & \text{if code} = 0 \\ \text{failed} & \text{if code} \ne 0 \end{cases}$$

---

## 6. Test Quality & Progressive Verification Rules

1. **No Facade Tests**: Every test performs genuine assertions against database records (`assertDatabaseHas`), HTTP response codes and structures (`assertStatus`, `assertJsonStructure`), queued jobs (`Queue::assertPushed`), or event broadcasts (`Event::assertDispatched`).
2. **Progressive Testability**: When an implementation milestone is in progress, upcoming milestone tests skip cleanly with informative messages (`Awaiting M2: ...`). As soon as workers introduce migrations, routes, or classes, tests automatically activate and assert real functionality.
3. **Deterministic Independence**: Each test method creates its own data state and cleans up via `RefreshDatabase`. No inter-test ordering dependencies.
