# Dead Ends Log — Orchestrator 11

| Iteration | Approach Tried | Why It Failed | Files Touched |
|-----------|---------------|---------------|---------------|
| M2-Iter1 | Artificial observer bypass check `$fromObserver` in `SyncPersonnelJob` | Caused zero-group unsegmented production deployments to drop camera sync, causing test regressions and failing forensic audit | `app/Jobs/SyncPersonnelJob.php`, `app/Observers/PersonnelObserver.php` |
| M2-Iter1 | Checking `!AccessGroup::where('is_active', true)->exists()` for fallback | Returning all devices when all existing access groups were deactivated inverted security isolation | `app/Services/AccessControlService.php` |
| M2-Iter1 | Hardcoded PostgreSQL `ilike` in `AccessGroupController` | Crashed on SQLite in-memory test database with syntax error | `app/Http/Controllers/AccessGroupController.php` |
