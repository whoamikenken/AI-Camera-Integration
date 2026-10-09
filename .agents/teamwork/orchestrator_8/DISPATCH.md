# DISPATCH DIRECTIVE — Orchestrator 8

## Mission
Implement enterprise biometric access control groups, domain lifecycle state machines, and bulk fleet campaigns alongside architectural telemetry decoupling, asynchronous downlink command correlation, Eloquent factory testing harnesses, API response uniformity, and frontend composables as specified in `system-evo.md`.

## Authoritative User Request
Refer to `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (section `## 2026-10-07T01:57:58Z`) and `/home/wsk-devops2/AI-Camera-Integration/system-evo.md`.

## Working Directory
`/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_8`

## Requirements

### R1. Granular Access Control Groups & Zone-Based Dispatching (Feature 1)
- Provide access control groups that map personnel and departments to authorized physical devices.
- Refactor personnel biometric synchronization so that provisioning and de-provisioning commands only dispatch to authorized devices in the relevant access groups rather than broadcasting globally to all active devices.
- Deliver full API endpoints and frontend management UI (`AccessGroupManager.vue`) to configure and inspect access group memberships and trigger manual zone re-synchronizations.

### R2. Resilient Domain Lifecycle State Machines (Feature 2)
- Support cancellation workflows for leave requests (reversing allocated balances and attendance statuses) and regularization requests before approval.
- Support visitor lifecycle state handling, including cancellation, automated detection of overstayed visitors, and expiration of no-show visits.
- Deliver automated background scheduled tasks or jobs to flag overstays and expire no-shows, with corresponding UI alerts and cancellation actions across employee and visitor dashboards.

### R3. Bulk Workforce Operations & Fleet Provisioning Campaigns (Feature 5)
- Support bulk batch campaigns for device maintenance (e.g., bulk reboot, bulk MQTT configuration updates) and personnel provisioning (batch face enrollment and deletion).
- Provide batch tracking for campaign status and execution progress, with selection toolbars and batch actions in device and personnel management views.

### R4. Two-Tier High-Throughput Telemetry Ingestion (Area 1)
- Decouple the primary MQTT listener daemon from heavy operations: the daemon must immediately acknowledge incoming camera packets (`PushAck`) and enqueue raw telemetry payloads into a Redis queue.
- Implement asynchronous workers to consume queued telemetry packets, decode biometric images, persist database records, and broadcast live events without blocking the listener thread.

### R5. Asynchronous Downlink Command Correlator (Area 2)
- Replace blocking sleep/loop downlink operations in HTTP request cycles with an asynchronous command ticket pattern.
- Record outbound device commands, return immediate accepted responses with command tracking tickets, and correlate incoming hardware acknowledgment packets via MQTT listeners to complete command tickets and notify the UI via WebSockets.

### R6. Complete Testing Harness & Gateway Decoupling (Area 3)
- Create Eloquent model factories with expressive states for core domain models (`Device`, `Employee`, `Personnel`, `Shift`, `AttendancePunch`, `Visitor`, `Visit`).
- Introduce a camera gateway contract with a fake/mock implementation for testing, removing hardcoded `app()->environment('testing')` conditionals from production services.

### R7. API Response Uniformity & Form Requests (Area 4)
- Standardize all API response envelopes with unified success/data/error formatting without altering root `/api/*` endpoint paths.
- Extract controller validation rules into dedicated Laravel Form Request classes.
- Integrate automated OpenAPI documentation generation (e.g., Scramble).

### R8. Frontend Composable Architecture (Area 5)
- Extract reusable Vue 3 composables (`usePaginatedResource`, `useLiveTelemetryStream`, `useBiometricCapture`) to eliminate duplicated pagination, filtering, WebSocket lifecycle, and camera capture logic across frontend telemetry and management views.

## Verification Resources & Test Suites
- Existing feature test suite (`tests/Feature/`) and Laravel artisan test runner (`php artisan test`).
- Frontend Vite build toolchain (`npm run build`).

## Acceptance Criteria

### Domain Logic & Edge Synchronization
- [ ] Personnel synchronization commands are scoped strictly to devices mapped to the person's access group(s).
- [ ] Cancelling an approved leave request restores leave balance and updates affected attendance records.
- [ ] Overstayed visits and no-show visits are automatically identified and flagged with appropriate state transitions.
- [ ] Bulk personnel and device campaign endpoints execute batch operations and report progress.

### Architectural Decoupling & DX
- [ ] `MqttListenCommand` acknowledges telemetry and offloads image processing/persistence to the background queue without synchronous disk/S3 or decoding overhead.
- [ ] Downlink commands return non-blocking accepted tickets with correlation on matching hardware ACK packets.
- [ ] All `app()->environment('testing')` branches in production camera services are removed and replaced with camera gateway abstractions.
- [ ] Eloquent factories exist and function for all primary models.
- [ ] API responses follow a consistent envelope structure across controllers, with validation handled via Form Requests.
- [ ] Interactive OpenAPI documentation is accessible at `/docs/api`.

### Frontend & Build Quality
- [ ] Common pagination, live WebSocket streaming, and biometric webcam capture are refactored into composables and used in relevant Vue views.
- [ ] All unit and feature tests pass cleanly via `php artisan test`.
- [ ] Frontend builds without TypeScript or bundling errors via `npm run build`.


## 2026-10-07T01:59:38Z
You are the Project Orchestrator (orchestrator_8) for the Intelligent AI Camera Hub project.
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_8
Your dispatch directive is located at: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_8/DISPATCH.md
The authoritative user request is in: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md (under header ## 2026-10-07T01:57:58Z)
Detailed architectural specification is in: /home/wsk-devops2/AI-Camera-Integration/system-evo.md

Your mission:
Implement enterprise biometric access control groups, domain lifecycle state machines, and bulk fleet campaigns alongside architectural telemetry decoupling, asynchronous downlink command correlation, Eloquent factory testing harnesses, API response uniformity, and frontend composables as specified in system-evo.md and DISPATCH.md.

Decompose the requirements into subtasks, spawn and direct specialist subagents, monitor progress, ensure all tests pass (php artisan test) and frontend builds cleanly (npm run build). Maintain your BRIEFING.md and progress.md in your working directory. When all acceptance criteria are met, deliver your completion report with evidence.
