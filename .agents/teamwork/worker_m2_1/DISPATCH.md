# Dispatch Directive — Worker Milestone 2 (SEC-13, SEC-15, SEC-16, SEC-19)

## Mission
Implement edge ingestion & input security remediations across MQTT, photo uploads, anti-SSRF, and webhook reverse-proxy loopback authentication.

## Authoritative Inputs
- User Request: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md`
- Tasks Spec: `/home/wsk-devops2/AI-Camera-Integration/tasks-security.md` (SEC-13, SEC-15, SEC-16, SEC-19)
- Technical Blueprint: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_survey_2/handoff.md`

## Exclusively Owned Files
- `start-dev.sh`
- `.env.example`
- `.env`
- `app/Console/Commands/MqttListenCommand.php`
- `app/Http/Controllers/PersonnelController.php`
- `app/Services/ImageStorageService.php`
- `app/Http/Controllers/HttpWebhookController.php`
- `bootstrap/app.php`
- `tests/Feature/TelemetryDeduplicationTest.php`
- `tests/Feature/SecurityRemediationTest.php`

## Implementation Tasks
1. **SEC-13**:
   - In `start-dev.sh`: set `ENABLE_INSECURE_MQTT_TUNNEL=false` by default (`if [ "${ENABLE_INSECURE_MQTT_TUNNEL:-false}" = "true" ]; then`).
   - In `.env.example` and `.env`: ensure `ENABLE_INSECURE_MQTT_TUNNEL=false`.
   - In `MqttListenCommand.php`:
     - In `handleMessage`: only update `last_heartbeat_at = now()` if the device is active; do NOT overwrite `is_active => true`.
     - In `handleVerifyPush`, `handleStrangerSnapPush`, `handleDeviceAlert`: verify device enrollment in `devices` table. If not found or `is_active === false`, drop/reject telemetry immediately without creating an active device. (If not found, stage with `is_active = false` like webhook handler, but do NOT process or broadcast).
     - In `handleHeartbeat` and `handleOnlineStatus`: if device not found, create with `is_active = false`. Only broadcast online/heartbeat events if device is active.
   - In `tests/Feature/TelemetryDeduplicationTest.php`: pre-enroll test camera `1026230` with `is_active = true` so MQTT deduplication tests pass.
2. **SEC-15**:
   - In `PersonnelController.php`: replace `'photo' => 'nullable|image|max:10240'` with `'photo' => 'nullable|file|mimes:jpeg,jpg,png,webp|max:10240'` in both `store` and `update`.
   - In `ImageStorageService.php` (`getMedia`): reject SVG, XML, HTML files by returning `null` when requested path has `.svg`, `.xml`, `.html`, or SVG mime types.
3. **SEC-16**:
   - In `PersonnelController.php`: apply `filter_var(..., FILTER_VALIDATE_URL)` and `isSafeUrl(...)` check symmetrically to both `photo_url` and `photo_path` in `store` and `update`. Throw `ValidationException` immediately if an unsafe address (private IP, loopback, cloud metadata `169.254.169.254`) is provided in either field. Ensure relative paths (e.g. `strangers/test.jpg`) remain allowed when not a URL.
4. **SEC-19**:
   - In `HttpWebhookController.php` (`authenticateWebhook`): restrict loopback IP bypass to non-production environments: `if ($clientIp === $device->ip_address || (app()->environment('local', 'testing') && in_array($clientIp, ['127.0.0.1', '::1'], true)))`.
   - In `bootstrap/app.php`: configure `$middleware->trustProxies(at: '*');` so reverse proxy client IPs are resolved correctly.
5. **Tests**:
   - Add dedicated test methods in `tests/Feature/SecurityRemediationTest.php` verifying SEC-13, SEC-15, SEC-16, SEC-19.
   - Run tests: `php artisan test --filter=SecurityRemediationTest`, `php artisan test --filter=TelemetryDeduplicationTest`, `php artisan test`. Ensure zero regressions.

## MANDATORY INTEGRITY WARNING
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A teamwork_preview_auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

## Working Directory & Handoff
Working directory: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m2_1`
Write handoff report to: `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m2_1/handoff.md`
Report back via `send_message` when done.


## 2026-10-07T06:54:43Z
From: 71aec755-ccf7-4da2-9035-e66085685b0c
Priority: HIGH

You are assigned as Worker for Milestone 2: Edge Ingestion & Input Security (SEC-13, SEC-15, SEC-16, SEC-19) in Intelligent AI Camera Hub.

Your working directory is:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m2_1

Your detailed dispatch directive is at:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/worker_m2_1/DISPATCH.md

Authoritative user request (MUST read before starting):
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md

Technical Blueprint & Investigation Report:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_survey_2/handoff.md

Tasks Specification:
/home/wsk-devops2/AI-Camera-Integration/tasks-security.md (SEC-13, SEC-15, SEC-16, SEC-19)

Exclusively Owned Files:
- start-dev.sh
- .env.example
- .env
- app/Console/Commands/MqttListenCommand.php
- app/Http/Controllers/PersonnelController.php
- app/Services/ImageStorageService.php
- app/Http/Controllers/HttpWebhookController.php
- bootstrap/app.php
- tests/Feature/TelemetryDeduplicationTest.php
- tests/Feature/SecurityRemediationTest.php

Key Tasks:
1. SEC-13: In start-dev.sh, .env.example, .env set ENABLE_INSECURE_MQTT_TUNNEL=false by default. In MqttListenCommand.php, reject/drop telemetry from un-enrolled or inactive devices; do not auto-create active devices; do not overwrite is_active=true on heartbeat. In TelemetryDeduplicationTest.php pre-enroll camera 1026230 as active.
2. SEC-15: In PersonnelController.php restrict photo upload mime types to mimes:jpeg,jpg,png,webp. In ImageStorageService.php getMedia, reject SVG/XML/HTML.
3. SEC-16: In PersonnelController.php check isSafeUrl on both photo_url and photo_path if valid URL format.
4. SEC-19: In HttpWebhookController.php limit loopback IP bypass to local/testing environments. In bootstrap/app.php configure $middleware->trustProxies(at: '*').
5. Add test coverage in tests/Feature/SecurityRemediationTest.php for SEC-13, SEC-15, SEC-16, SEC-19. Run tests: php artisan test --filter=SecurityRemediationTest, php artisan test --filter=TelemetryDeduplicationTest, and php artisan test. Ensure zero regressions.
