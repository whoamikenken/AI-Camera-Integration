# Dispatch Directive: Explorer Survey 3 (Environment, CSP, Dependencies & Test Suites)

## Working Directory
`/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_survey_3`

## Authoritative Reference
`/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md`
`/home/wsk-devops2/AI-Camera-Integration/tasks-security.md`
`/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/orchestrator_7/SCOPE.md`

## Assignment
Investigate the current codebase for SEC-17 and SEC-18, and map the test suites:
1. **SEC-17**: Check `app/Http/Middleware/SecurityHeaders.php`. Analyze current Content-Security-Policy header values (`script-src`, `connect-src`, `img-src`, `'unsafe-inline'`, `'unsafe-eval'`). Determine what tightening is needed to restrict script evaluation, remove wildcards, and scope origins safely without breaking Vue/Vite or Reverb.
2. **SEC-18**: Check `package.json`, `package-lock.json`, `composer.json`, and `composer.lock`. Analyze dependencies flagged in SEC-18:
   - `@vue/server-renderer` (GHSA-g2v6-rqmx-r4w6)
   - `source-map-js` (GHSA-68fv-2mgg-jv7q)
   - `concurrently` / `shell-quote` (GHSA-pqg4-j6r4-53mv)
   - `laravel/framework` (>=12.69.0 / CVE-2026-102279)
   - `league/commonmark` (GHSA-3q6v-r5mr-hxv8)
   - `league/flysystem` (CVE-2026-102601)
   Determine current installed versions and compatibility of target versions.
3. **Test Suites & Verification**: Examine existing security test suites:
   - `tests/Feature/SecurityRemediationTest.php`
   - `tests/Feature/SecurityAdversarialGateTest.php`
   - `tests/Feature/MediaAccessAndUnauthenticatedRouteTest.php`
   Analyze what tests currently exist, what tests fail or pass, and what new test assertions must be added to verify SEC-11 through SEC-19.

## Output
Write your findings and evidence chain to `/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_survey_3/handoff.md`.
Report back when finished.


## 2026-10-07T01:50:26Z
You are Explorer Survey 3 investigating Environment, CSP, Dependencies & Test Suites (SEC-17, SEC-18).
Your assigned working directory is: /home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_survey_3

MANDATORY: You MUST read the authoritative user request at:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/ORIGINAL_REQUEST.md
Also read:
/home/wsk-devops2/AI-Camera-Integration/tasks-security.md
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_survey_3/DISPATCH.md

Investigate the following security findings in the codebase:
1. SEC-17 (Medium): Harden Content-Security-Policy Directives
   - Inspect app/Http/Middleware/SecurityHeaders.php.
   - Analyze current CSP directives: script-src, connect-src, img-src.
   - Determine how to restrict connect-src (e.g. self, Reverb WebSocket host/port, Cloudflare insights), img-src (self, data:, blob:, cloud storage), and restrict script evaluation without breaking Vue/Vite or Reverb.
2. SEC-18 (Medium): Update Vulnerable NPM and Composer Upstream Dependencies
   - Inspect package.json, package-lock.json, composer.json, composer.lock.
   - Check status of:
     * @vue/server-renderer (GHSA-g2v6-rqmx-r4w6)
     * source-map-js (GHSA-68fv-2mgg-jv7q)
     * concurrently / shell-quote (GHSA-pqg4-j6r4-53mv)
     * laravel/framework (>=12.69.0 / CVE-2026-102279)
     * league/commonmark (GHSA-3q6v-r5mr-hxv8)
     * league/flysystem (CVE-2026-102601)
   - Determine current versions and safe patch targets.
3. Security Test Suite Mapping:
   - Inspect tests/Feature/SecurityRemediationTest.php, tests/Feature/SecurityAdversarialGateTest.php, tests/Feature/MediaAccessAndUnauthenticatedRouteTest.php.
   - Analyze existing test assertions and determine what new tests must be added to cover SEC-11 through SEC-19.

Write your comprehensive investigation report to:
/home/wsk-devops2/AI-Camera-Integration/.agents/teamwork/teamwork_preview_explorer_survey_3/handoff.md
When finished, notify your parent with send_message.
