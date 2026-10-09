# Dispatch Directive: Explorer Survey 2 (Edge Ingestion & Input Security)

## Working Directory
`/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_survey_2`

## Authoritative Reference
`/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md`
`/home/wsk-devops2/AI-Camera-Integration/tasks-security.md`
`/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_7/SCOPE.md`

## Assignment
Investigate the current codebase for SEC-13, SEC-15, SEC-16, and SEC-19:
1. **SEC-13**: Check `start-dev.sh`, `.env.example`, `.env`, and `app/Console/Commands/MqttListenCommand.php`. Analyze how `bore` or MQTT tunneling is configured, whether `ENABLE_INSECURE_MQTT_TUNNEL` exists, and how `MqttListenCommand` handles un-enrolled / unregistered devices (e.g. `Device::firstOrCreate` vs dropping or setting `is_active = false`).
2. **SEC-15**: Check `app/Http/Controllers/PersonnelController.php`, `app/Services/ImageStorageService.php`, and `routes/api.php`. Analyze validation rules for biometric face photo uploads (checking if SVG is currently allowed, how to enforce `mimes:jpeg,jpg,png,webp`), and how `ImageStorageService::getMedia()` serves files (content-type, disposition, and prevention of script execution).
3. **SEC-16**: Check `app/Http/Controllers/PersonnelController.php` (`store` and `update` methods). Analyze how `photo_path` vs `photo_url` is handled, where SSRF checks (`isSafeUrl`) are applied, and where bypasses exist when `photo_path` contains an external URL.
4. **SEC-19**: Check `app/Http/Controllers/HttpWebhookController.php` and `bootstrap/app.php`. Analyze loopback IP check in `authenticateWebhook` (`127.0.0.1`, `::1`), how environment checks (`app()->environment('local', 'testing')`) should be restricted, and how trusted proxies should be configured via `$middleware->trustProxies(...)`.
5. Check existing tests in `tests/Feature/` that exercise these areas, note any existing failures or gaps.

## Output
Write your findings and evidence chain to `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_survey_2/handoff.md`.
Report back when finished.


## 2026-10-07T01:50:26Z
You are Explorer Survey 2 investigating Edge Ingestion & Input Security (SEC-13, SEC-15, SEC-16, SEC-19).
Your assigned working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_survey_2

MANDATORY: You MUST read the authoritative user request at:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
Also read:
/home/wsk-devops2/AI-Camera-Integration/tasks-security.md
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_survey_2/DISPATCH.md

Investigate the following security findings in the codebase:
1. SEC-13 (High): Disable Insecure WAN MQTT Tunnel & Prevent Rogue Device Ingestion
   - Inspect start-dev.sh, .env.example, .env, and app/Console/Commands/MqttListenCommand.php.
   - Analyze bore public tunnel configuration and how to set ENABLE_INSECURE_MQTT_TUNNEL=false by default.
   - Analyze how MqttListenCommand handles unrecognized device IDs (e.g. Device::firstOrCreate with is_active => true vs rejecting/inactivating unregistered devices like HttpWebhookController does).
2. SEC-15 (Medium): Restrict Biometric File Upload MIME Types to Disallow SVG / Prevent Stored XSS
   - Inspect app/Http/Controllers/PersonnelController.php, app/Services/ImageStorageService.php, and routes/api.php.
   - Analyze validation rules for photos: replace image validation with strict raster mimes (mimes:jpeg,jpg,png,webp).
   - Analyze ImageStorageService::getMedia() handling to prevent SVG execution or force safe headers/disposition.
3. SEC-16 (Medium): Enforce Consistent SSRF Protection on photo_path in PersonnelController
   - Inspect app/Http/Controllers/PersonnelController.php (store and update methods).
   - Trace how photo_url vs photo_path is handled. Find where photo_path containing an external URL bypasses isSafeUrl anti-SSRF validation.
   - Propose exact fix to enforce anti-SSRF validation consistently on both fields.
4. SEC-19 (Low): Prevent Reverse-Proxy Loopback IP Authentication Bypass in Webhooks
   - Inspect app/Http/Controllers/HttpWebhookController.php and bootstrap/app.php.
   - Trace authenticateWebhook loopback IP acceptance (127.0.0.1, ::1).
   - Restrict bypass to local/testing environments (app()->environment('local', 'testing')) and configure $middleware->trustProxies(...) in bootstrap/app.php.

Also check relevant test files in tests/Feature/.
Write your comprehensive investigation report to:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_survey_2/handoff.md
When finished, notify your parent with send_message.
