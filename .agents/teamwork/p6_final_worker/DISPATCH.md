## 2026-10-08T18:21:44Z

You are p6_final_worker (teamwork_preview_worker) for Phase 6 Performance Optimization (Milestone 5 Final Acceptance).

Working directory: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/p6_final_worker
Orchestrator directory: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_9
Original User Request: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
Scope document: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_9/SCOPE.md
Task matrix: /home/wsk-devops2/AI-Camera-Integration/tasks-performance.md

Your exclusive write ownership:
- tasks-performance.md

Tasks:
1. Update `tasks-performance.md`:
   Mark all Phase 6 tasks (Tasks 6.1 through 6.13) as completed by changing `- [ ]` to `- [x]`:
   - Task 6.1: Eliminate Non-SARGable whereDate() Expressions Across Attendance & Visitor Engines
   - Task 6.2: Add Missing Composite & Foreign Key Indexes for Telemetry & Punches
   - Task 6.3: Optimize Unbounded Table Scan on sync_tasks in Dashboard Stats
   - Task 6.4: Paginate and Column-Constrain Wide Read Endpoints in Leave & Organization Modules
   - Task 6.5: Eliminate O(N) Database Queries in Employee::isRestDay Inside Summary Loop
   - Task 6.6: Eliminate Linear O(N x M) Collection Scan and Large Outbox Pull in DeviceController::audit()
   - Task 6.7: Batch Multi-Record SQL Updates in DeviceAlertController::bulkUpdateStatus
   - Task 6.8: Eliminate Blocking Redis KEYS Command in Bulk Shift Assignment
   - Task 6.9: Cache Pre-Enrolled Device Existence in High-Frequency MQTT Telemetry Stream
   - Task 6.10: Cache Biometric customize_id to Employee Mapping in Punch Ingestion
   - Task 6.11: Cache Invalidation Engine for Device Alerts & Public Settings
   - Task 6.12: Fix Echo Channel Type Mismatch in DeviceAlertsCenter.vue
   - Task 6.13: Correct Metric Binding in attendanceStore from Server Summary

2. Run Verification Commands:
   - `php artisan test --filter=PerformanceOptimizationTest` (all 33 tests must pass!)
   - `php artisan test --filter=Phase6Milestone3Challenger1Test`
   - `php artisan test --filter=Phase6Milestone3Challenger2Test`
   - `npm run build`

MANDATORY INTEGRITY WARNING:
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A teamwork_preview_auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

Write your handoff report to /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/p6_final_worker/handoff.md and notify orchestrator via send_message.
