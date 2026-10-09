# DISPATCH DIRECTIVE — challenger_m5_2

## Identity
- **Agent:** `challenger_m5_2`
- **Role:** Hardware ACK & Correlator Challenger (Milestone M5)
- **Working Directory:** `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m5_2`
- **Parent Conversation ID:** `340b2ee2-86ac-4ca7-9f71-8c1542c65adb`

---

## Mandatory First Step
Read the following documents:
1. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (under Section `## 2026-10-07T01:57:58Z`)
2. `/home/wsk-devops2/AI-Camera-Integration/system-evo.md` (Area 2: Asynchronous Downlink Command Pattern)
3. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md` (Milestone M5)
4. Worker Handoff: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m5_1/handoff.md`

---

## Adversarial Challenge Scope
Empirically challenge the downlink correlator and hardware ACK state machine:
1. **Hardware ACK Return Codes**:
   - Verify `code: 0` -> `status: completed`.
   - Verify non-zero code (`code: 1`, `code: -1`) -> `status: failed` with `error_message` populated.
   - Verify non-zero response error string extraction (`desc`, `detail`, `error`).
2. **Unmatched / Corrupted ACK Packets**:
   - Send ACK packets with random, unknown `messageId`: verify system handles it gracefully, returning `null` without throwing exceptions or crashing the listener daemon.
   - Send ACK packet missing `messageId`: verify system returns `null` safely.
3. **Double ACK & Concurrency**:
   - Send duplicate ACK packets for the same ticket: verify ticket remains completed without integrity corruption.
   - Simulate concurrent commands across devices: verify ticket correlation isolates tickets by `message_id`.
4. Run tests:
   ```bash
   php artisan test --filter="test_f30|test_f31|test_f32|test_f33"
   php artisan test --filter="test_boundary_hardware_ack"
   php artisan test --filter="test_cross_bulk_fleet_campaign_correlates_individual_downlink_command_tickets"
   ```

Deliver verdict (`APPROVE` or `REQUEST_CHANGES`) with empirical test output in `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m5_2/handoff.md`.
Notify parent via `send_message`.

## 2026-10-09T00:25:29Z
You are challenger_m5_2.
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m5_2
Your dispatch directive is at: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m5_2/DISPATCH.md
Your parent is conversation ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb

MANDATORY FIRST STEP: Read the following documents:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md (under Section ## 2026-10-07T01:57:58Z)
2. /home/wsk-devops2/AI-Camera-Integration/system-evo.md (Area 2: Asynchronous Downlink Command Pattern)
3. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md (Milestone M5)
4. Worker Handoff: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m5_1/handoff.md
5. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m5_2/DISPATCH.md

Empirically challenge the downlink correlator and hardware ACK state machine (non-zero error codes, unknown messageId handling, duplicate ACKs, concurrent campaigns).
Run tests:
php artisan test --filter="test_f30|test_f31|test_f32|test_f33"
php artisan test --filter="test_boundary_hardware_ack"
php artisan test --filter="test_cross_bulk_fleet_campaign_correlates_individual_downlink_command_tickets"

Deliver your verdict (APPROVE or REQUEST_CHANGES) in /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/challenger_m5_2/handoff.md and notify parent via send_message.
