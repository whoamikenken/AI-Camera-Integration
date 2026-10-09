# DISPATCH DIRECTIVE — reviewer_m5_2

## Identity
- **Agent:** `reviewer_m5_2`
- **Role:** System & Hardware Reviewer (Downlink Correlator, Gateway Contracts, & Broadcast Event)
- **Working Directory:** `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/reviewer_m5_2`
- **Parent Conversation ID:** `340b2ee2-86ac-4ca7-9f71-8c1542c65adb`

---

## Mandatory First Step
Read the following documents:
1. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md` (under Section `## 2026-10-07T01:57:58Z`)
2. `/home/wsk-devops2/AI-Camera-Integration/system-evo.md` (Area 2: Asynchronous Downlink Command Pattern)
3. `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md` (Milestone M5: Features #30, #31, #32, #33)
4. Worker Handoff: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m5_1/handoff.md`

---

## Review Scope & Instructions
Review the downlink command correlator, gateway contracts, model, and broadcast events:
1. `database/migrations/2026_10_08_000003_create_device_commands_table.php` & `app/Models/DeviceCommand.php`:
   - Inspect table schema, columns (`device_id`, `message_id`, `operator`, `status`, `payload`, `response`, `error_message`, `dispatched_at`, `completed_at`), indexes, and Eloquent relationships.
   - Inspect status transition helpers `markCompleted` and `markFailed`.
2. `app/Contracts/CameraGatewayInterface.php` & Gateways (`MqttCameraGateway`, `FakeCameraGateway`, `HttpCameraGateway`, `CameraMqttService`):
   - Verify `dispatchCommandAsync` signature and return type (`DeviceCommand`).
   - Verify non-blocking behavior: returns pending ticket without sleeping or waiting for reply.
3. `app/Services/CameraMqttService.php::handleCommandAck` & `app/Console/Commands/MqttListenCommand.php::handleCommandAck`:
   - Verify incoming ACK packets on `mqtt/face/+/Ack` match ticket by `messageId`.
   - Verify `code === 0` sets `'completed'`.
   - Verify non-zero code sets `'failed'`.
   - Verify unmatched `messageId` is handled gracefully without exceptions.
4. `app/Events/DeviceCommandCompleted.php` & `routes/channels.php`:
   - Verify broadcast implementation on `PrivateChannel('device-commands')` and channel authorization.
5. `app/Http/Controllers/DeviceController.php` & `routes/api.php`:
   - Verify asynchronous reboot returns HTTP 202 Accepted.
   - Verify `GET /api/device-commands/{command}` route.

Run tests:
```bash
php artisan test --filter="test_f3[0-3]"
php artisan test --filter="test_boundary_hardware_ack"
php artisan test --filter="test_cross_bulk_fleet_campaign_correlates_individual_downlink_command_tickets"
php artisan test --filter=Milestone5LayoutAndA11yChallengeTest
npm run build
```

Deliver verdict (`APPROVE` or `REQUEST_CHANGES`) with detailed findings in `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/reviewer_m5_2/handoff.md`.
Notify parent via `send_message`.

## 2026-10-09T00:25:29Z
You are reviewer_m5_2.
Your working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/reviewer_m5_2
Your dispatch directive is at: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/reviewer_m5_2/DISPATCH.md
Your parent is conversation ID: 340b2ee2-86ac-4ca7-9f71-8c1542c65adb

MANDATORY FIRST STEP: Read the following documents:
1. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md (under Section ## 2026-10-07T01:57:58Z)
2. /home/wsk-devops2/AI-Camera-Integration/system-evo.md (Area 2: Asynchronous Downlink Command Pattern)
3. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_11/PROJECT.md (Milestone M5: Features #30, #31, #32, #33)
4. Worker Handoff: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m5_1/handoff.md
5. /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/reviewer_m5_2/DISPATCH.md

Review all downlink correlator, gateway contracts, models, controllers, and broadcast events.
Run tests and build:
php artisan test --filter="test_f3[0-3]"
php artisan test --filter="test_boundary_hardware_ack"
php artisan test --filter="test_cross_bulk_fleet_campaign_correlates_individual_downlink_command_tickets"
php artisan test --filter=Milestone5LayoutAndA11yChallengeTest
npm run build

Deliver your verdict (APPROVE or REQUEST_CHANGES) in /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/reviewer_m5_2/handoff.md and notify parent via send_message.
