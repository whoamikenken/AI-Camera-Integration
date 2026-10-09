# Progress — Milestone M2 Empirical Challenger

**Last visited**: 2026-10-08T00:59:15Z
**Current Status**: Empirical testing complete. Findings documented. Verdict determined: REQUEST_CHANGES.

## Task Checklist
- [x] Record dispatch & initialize BRIEFING.md / progress.md
- [x] Read worker handoff and relevant M2 implementation files
- [x] Run existing tests (`php artisan test --filter=Tier3CrossFeatureTest`, `Tier4RealWorldScenariosTest`, and M2 unit/feature tests)
- [x] Empirically stress-test Access Group API endpoints and Zone Resync:
  - [x] `POST /api/access-groups/{id}/sync-now` with:
    - [x] 0 devices, 0 personnel (HTTP 200, 0 jobs dispatched)
    - [x] 5 devices, 20 personnel (HTTP 200, 100 jobs dispatched)
    - [x] Inactive devices in the group (skipped properly, only active devices synced)
  - [x] CRUD validation: duplicate `code` (422), invalid IDs (404), invalid FKs (422), updating relationships (clean pivot sync), entity safety on deletion
  - [x] Hierarchical department inheritance (descendants in zone resync, ancestors in authorization)
- [x] Check for edge cases, failure modes, race conditions, blast radius:
  - [x] Verified Defect 1: Inactive access groups trigger zero-group fallback in `AccessControlService.php`, opening all cameras to all users
  - [x] Verified Defect 2: Observer sync suppressed in zero-group mode via `$fromObserver` in `SyncPersonnelJob.php`, breaking production camera sync
  - [x] Discovered Defect 3: Hardcoded `ilike` in `AccessGroupController.php` causes 500 error under SQLite test runner
- [x] Document findings, compose handoff.md, determine verdict: REQUEST_CHANGES
- [ ] Notify parent agent via send_message
