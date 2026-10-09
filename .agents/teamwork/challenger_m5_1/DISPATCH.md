# DISPATCH DIRECTIVE — challenger_m5_1

## Identity
- **Agent:** `challenger_m5_1`
- **Role:** Adversarial Telemetry & Invariants Challenger (Milestone M5)
- **Working Directory:** `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m5_1`
- **Parent Conversation ID:** `340b2ee2-86ac-4ca7-9f71-8c1542c65adb`

---

## Mandatory First Step
Read the following documents:
1. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (under Section `## 2026-10-07T01:57:58Z`)
2. `/home/wsk-devops2/AI-Camera-Integration/system-evo.md` (Areas 1 & 2)
3. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md` (Milestone M5)
4. Worker Handoff: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m5_1/handoff.md`

---

## Adversarial Challenge Scope
Empirically stress-test the two-tier telemetry pipeline and verify strict invariants:
1. **Zero-Latency Ingestion Verification**:
   - Verify that `MqttListenCommand::handleVerifyPush` sends `PushAck` immediately upon receiving packet with `RecordID`.
   - Verify that no heavy disk I/O or Base64 decoding occurs inside the listener command thread.
2. **Telemetry Invariant Testing**:
   - Execute telemetry ingestion with empty, corrupted, and large base64 image payloads.
   - Verify that null images produce `null` URLs without throwing unhandled exceptions.
   - Verify deduplication: identical `RecordID` packets within 60 seconds are acknowledged via `PushAck` but not re-queued to the database.
   - Verify that inactive or unenrolled device packets are dropped per SEC-13.
3. **Queue Invariant Testing**:
   - Verify `ProcessTelemetryPacketJob` on queue `camera-telemetry`.
   - Verify that `verify_status === 1` dispatches `ProcessAttendancePunchJob`.
4. Run tests:
   ```bash
   php artisan test --filter="test_f27|test_f28|test_f29"
   php artisan test --filter="test_boundary_telemetry_packet"
   php artisan test --filter="test_scenario_10"
   ```

Deliver verdict (`APPROVE` or `REQUEST_CHANGES`) with empirical test output in `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m5_1/handoff.md`.
Notify parent via `send_message`.


## 2026-10-09T00:25:29Z
You are challenger_m5_1.
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m5_1
Your dispatch directive is at: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m5_1/DISPATCH.md
Your parent is conversation ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb

MANDATORY FIRST STEP: Read the following documents:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md (under Section ## 2026-10-07T01:57:58Z)
2. /home/wsk-devops2/AI-Camera-Integration/system-evo.md (Areas 1 & 2)
3. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md (Milestone M5)
4. Worker Handoff: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m5_1/handoff.md
5. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m5_1/DISPATCH.md

Empirically stress-test the two-tier telemetry pipeline and verify strict invariants (null image handling, dedup, SEC-13 inactive device drop, ProcessAttendancePunchJob triggering).
Run tests:
php artisan test --filter="test_f27|test_f28|test_f29"
php artisan test --filter="test_boundary_telemetry_packet"
php artisan test --filter="test_scenario_10"

Deliver your verdict (APPROVE or REQUEST_CHANGES) in /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m5_1/handoff.md and notify parent via send_message.
