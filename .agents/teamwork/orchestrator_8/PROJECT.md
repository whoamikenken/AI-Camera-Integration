# Project: Intelligent AI Camera Hub Enterprise Evolution

## Architecture
The system follows a pure WAN MQTT architecture with Laravel 11/PHP 8.2 backend, PostgreSQL 16 database, Redis queues, Horizon worker pool, Laravel Reverb WebSockets, and Vue 3 frontend (Vite + Pinia).
This evolution introduces:
1. **Testing Foundations & Gateway Decoupling**: Expressive Eloquent model factories and `CameraGatewayInterface` fake/mock abstractions, completely eliminating `app()->environment('testing')` in production code.
2. **Access Control Groups & Zone-Based Dispatching**: Domain models and pivots (`access_groups`, `access_group_device`, `access_group_personnel`, `access_group_department`), `AccessControlService`, scoping `SyncPersonnelJob` strictly to authorized devices.
3. **Resilient Domain Lifecycle State Machines**: Comprehensive cancellation flows for leaves (balance restoration and attendance rollback/recalculation), regularization cancellation, and visitor lifecycle automation (`DetectOverstayVisitorsJob` and `ExpireNoShowVisitsJob`).
4. **Bulk Workforce Operations & Fleet Provisioning Campaigns**: `bulk_campaigns` entity, asynchronous batch jobs for fleet reboot/MQTT sync, high-throughput `AddPersons` (up to 50 persons per packet), and batch UI toolbars.
5. **Two-Tier Telemetry Ingestion & Downlink Correlator**: Decoupled zero-latency MQTT listener (`PushAck` <2ms) offloading to Redis queue `camera-telemetry` processed by `ProcessTelemetryPacketJob`. Asynchronous downlink command tickets (`device_commands`, `202 Accepted`) correlated by `messageId` on incoming hardware ACKs.
6. **API Response Uniformity & Frontend Composables**: Standardized `ApiResponse` envelope preserving legacy keys for tests and frontend compatibility, dedicated Form Requests, Dedoc Scramble OpenAPI docs at `/docs/api`, and reusable Vue composables (`usePaginatedResource`, `useLiveTelemetryStream`, `useBiometricCapture`).
7. **E2E Verification & Coverage Hardening**: Complete automated verification (`php artisan test`, `npm run build`) with adversarial stress tests.

## Feature Inventory
| # | Feature | Description | Milestone | Source |
|---|---------|-------------|-----------|--------|
| 1 | Comprehensive Eloquent Model Factories | 10 factories with expressive states (Device, Personnel, Employee, Shift, AttendancePunch, Visitor, Visit, etc.) | M1 | Survey 2 |
| 2 | Camera Gateway Abstraction | `CameraGatewayInterface`, `MqttCameraGateway`, `HttpCameraGateway` | M1 | Survey 2 |
| 3 | Fluent Mocking Camera Gateway | `FakeCameraGateway` with fluent assertions (`CameraGateway::fake()`) | M1 | Survey 2 |
| 4 | Removal of Production Testing Conditionals | Eliminate all 4 `app()->environment('testing')` blocks in `CameraMqttService` | M1 | Survey 2 |
| 5 | Access Group Entity (`access_groups`) | Organizational security zone entity mapping personnel and devices | M2 | Survey 1 |
| 6 | Group-to-Device Pivot (`access_group_device`) | Pivot table linking access groups to physical camera devices | M2 | Survey 1 |
| 7 | Group-to-Personnel Pivot (`access_group_personnel`) | Pivot table linking access groups to specific personnel | M2 | Survey 1 |
| 8 | Group-to-Department Pivot (`access_group_department`) | Pivot table auto-granting access based on department membership | M2 | Survey 1 |
| 9 | Target Device Resolution Service | `AccessControlService::getAuthorizedDevicesForPersonnel()` | M2 | Survey 1 |
| 10 | Zone-Scoped Personnel Sync | `SyncPersonnelJob` refactored to dispatch only to authorized devices | M2 | Survey 1 |
| 11 | Access Group Zone Re-sync Endpoint | `POST /api/access-groups/{id}/sync-now` | M2 | Survey 1 |
| 12 | Access Group Manager UI | `AccessGroupManager.vue` in Settings Hub | M2 | Survey 1 |
| 13 | Leave Request Cancellation Workflow | `cancelLeaveRequest` in `LeaveService` with atomic balance restoration | M3 | Survey 1 |
| 14 | Attendance Status Rollback & Recalculation | Rollback of 'on_leave' attendance records upon leave cancellation | M3 | Survey 1 |
| 15 | Regularization Cancellation Workflow | Cancellation of unapproved regularization requests | M3 | Survey 1 |
| 16 | Visitor Cancellation & Face Revocation | Cancellation of visits with immediate edge camera face de-provisioning | M3 | Survey 1 |
| 17 | Overstayed Visitor Detection Job | Scheduled job `DetectOverstayVisitorsJob` (every 15m) with DeviceAlert | M3 | Survey 1 |
| 18 | No-Show Visit Expiration Job | Scheduled job `ExpireNoShowVisitsJob` (midnight) expiring past visits | M3 | Survey 1 |
| 19 | Overstayed Visits Endpoint | `GET /api/visits/overstayed` | M3 | Survey 1 |
| 20 | Bulk Campaigns Tracking Entity | Migration and `BulkCampaign` model tracking asynchronous batch tasks | M4 | Survey 1 |
| 21 | Fleet Bulk Reboot Endpoint & Job | Asynchronous batch reboot across selected cameras with rate limiting | M4 | Survey 1 |
| 22 | Fleet Bulk MQTT Sync Endpoint & Job | Asynchronous batch MQTT parameter updates (`UpMQTTconfig`) | M4 | Survey 1 |
| 23 | High-Throughput Bulk Personnel Sync | Batching face records into `AddPersons` (up to 50 persons per packet) | M4 | Survey 1 |
| 24 | Bulk Personnel Deletion Endpoint & Job | Batch deletion across personnel and edge cameras | M4 | Survey 1 |
| 25 | Bulk Campaign Progress API | `GET /api/bulk-campaigns/{id}` returning execution progress | M4 | Survey 1 |
| 26 | Fleet & Personnel Batch Toolbars | Multi-select checkboxes and batch actions in `DeviceManager.vue` & `PersonnelManager.vue` | M4 | Survey 1 |
| 27 | Zero-Latency Telemetry Ingestion (Tier 1) | `MqttListenCommand` immediate `PushAck` (<2ms) and enqueue to Redis | M5 | Survey 2 |
| 28 | Asynchronous Telemetry Worker (Tier 2) | `ProcessTelemetryPacketJob` on Redis queue `camera-telemetry` | M5 | Survey 2 |
| 29 | Horizon Configuration for Telemetry | Configure supervisor queue `camera-telemetry` in `config/horizon.php` | M5 | Survey 2 |
| 30 | Downlink Command Table & Model | Migration `device_commands` and model `DeviceCommand` | M5 | Survey 2 |
| 31 | Non-blocking Downlink Ticket Dispatch | `CameraMqttService::dispatchCommandAsync` & `202 Accepted` response | M5 | Survey 2 |
| 32 | Hardware ACK Correlation | Correlation in `MqttListenCommand` by `messageId` updating tickets | M5 | Survey 2 |
| 33 | Downlink Event Broadcasting | Broadcast `DeviceCommandCompleted` via Laravel Reverb | M5 | Survey 2 |
| 34 | Standard API Response Envelope | `ApiResponse` helper with dual-compatibility for tests & legacy keys | M6 | Survey 3 |
| 35 | Hardware Webhook Protocol Exemption | Strict protocol preservation on `/Subscribe/*` for edge firmware | M6 | Survey 3 |
| 36 | Dedicated Form Requests | 24 Form Request classes across Employee, Device, Shift, Visitor, Leave, etc. | M6 | Survey 3 |
| 37 | Automated OpenAPI Documentation | Dedoc Scramble integration at `/docs/api` with Bearer token security | M6 | Survey 3 |
| 38 | Universal Paginated Resource Composable | `usePaginatedResource.js` with debounce and universal parser | M6 | Survey 3 |
| 39 | Live Telemetry Stream Composable | `useLiveTelemetryStream.js` with private Echo channel & chime audio | M6 | Survey 3 |
| 40 | Biometric Capture Composable | `useBiometricCapture.js` with aspect ratio 1:1 square crop | M6 | Survey 3 |
| 41 | Frontend View Refactoring | Refactor 7 views to consume the composables | M6 | Survey 3 |
| 42 | E2E Testing Suite Pass (Tiers 1-4) | Pass 100% of requirement-driven E2E tests | M7 | System |
| 43 | Adversarial Coverage Hardening (Tier 5) | White-box stress tests, edge case validation, and clean audit | M7 | System |

## Milestones
| # | Name | Scope | Dependencies | Status |
|---|------|-------|-------------|--------|
| M1 | Testing Harness & Gateway Decoupling | Eloquent Model Factories & `CameraGatewayInterface` Fake | None | PLANNED |
| M2 | Access Control Groups & Zone-Based Dispatching | `access_groups`, pivots, `AccessControlService`, `SyncPersonnelJob` scoping, `AccessGroupManager.vue` | M1 | PLANNED |
| M3 | Resilient Domain Lifecycle State Machines | Leave cancellation, regularization cancellation, visit cancellation, overstay & no-show jobs | M1 | PLANNED |
| M4 | Bulk Workforce Operations & Fleet Campaigns | `bulk_campaigns`, batch reboot/MQTT sync, `AddPersons` batched sync, batch UI toolbars | M1, M2 | PLANNED |
| M5 | Two-Tier Telemetry Ingestion & Downlink Correlator | Zero-latency `PushAck` + Redis queue `camera-telemetry`, `ProcessTelemetryPacketJob`, `device_commands` correlator | M1 | PLANNED |
| M6 | API Uniformity, Form Requests, OpenAPI & Composables | `ApiResponse`, Form Requests, Scramble at `/docs/api`, Vue 3 composables & view refactoring | M1 | PLANNED |
| M7 | E2E Verification & Adversarial Coverage Hardening | Full PHPUnit test suite pass, Vite build pass, Challenger & Forensic Auditor gates | M1, M2, M3, M4, M5, M6 | PLANNED |

## Interface Contracts
### CameraGateway ↔ Device / Personnel Services
- `CameraGatewayInterface::publishCommand(Device $device, string $operator, array $params = [], int $timeoutSeconds = 3): array`
- `CameraGatewayInterface::dispatchCommandAsync(Device $device, string $operator, array $params = []): DeviceCommand`
- `FakeCameraGateway::fake(array $responses = []): void`
- `FakeCameraGateway::assertDispatched(string $operator, ?callable $callback = null): void`

### AccessControlService ↔ SyncPersonnelJob
- `AccessControlService::getAuthorizedDevicesForPersonnel(Personnel $personnel): Collection<Device>`
- Returns deduplicated collection of active `Device` instances matching direct personnel or departmental group assignments.
- Fallback: if total system `AccessGroup::count() === 0`, returns `Device::where('is_active', true)->get()`.

### LeaveService ↔ Attendance Processing
- `LeaveService::cancelLeaveRequest(LeaveRequest $request, ?User $user, ?string $reason): LeaveRequest`
- Atomically restores `LeaveBalance` in DB transaction (`lockForUpdate`).
- Calls `AttendanceProcessingService::processDay($employee, $date)` on past/today records to recalculate attendance.

### MqttListenCommand ↔ Telemetry Queue
- `PushAck` sent immediately upon receipt of `VerifyPush` / `StrSnapPush` (<2ms).
- Raw JSON packet enqueued to Redis `camera-telemetry` connection.
- `ProcessTelemetryPacketJob::handle()` executes image decoding, PostgreSQL insert, and Reverb broadcasting.

### MqttListenCommand ↔ Downlink Correlator
- Downlink command creates `device_commands` record with `message_id`.
- Hardware ACK packet on `mqtt/face/{DeviceID}/Ack` matched by `messageId` in `handleCommandAck()`.
- Updates `device_commands` status to `'completed'` and emits `DeviceCommandCompleted($command)`.

## Code Layout
- Backend Models: `app/Models/` (`AccessGroup`, `BulkCampaign`, `DeviceCommand`, etc.)
- Database Migrations: `database/migrations/`
- Database Factories: `database/factories/`
- Services & Gateways: `app/Services/`, `app/Contracts/`, `app/Gateways/`
- Background Jobs: `app/Jobs/` (`ProcessTelemetryPacketJob`, `DetectOverstayVisitorsJob`, `ExpireNoShowVisitsJob`, etc.)
- API Requests: `app/Http/Requests/` (`Employee/`, `Device/`, `Shift/`, `Visitor/`, `Leave/`, etc.)
- Controllers: `app/Http/Controllers/`
- Frontend Composables: `resources/js/composables/` (`usePaginatedResource.js`, `useLiveTelemetryStream.js`, `useBiometricCapture.js`)
- Frontend Components & Views: `resources/js/components/`, `resources/js/views/`
