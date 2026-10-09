| Iteration | Approach Tried | Why It Failed | Files Touched |
|-----------|---------------|---------------|---------------|
| 1 | Artificial observer bypass (`$fromObserver = true`) in `SyncPersonnelJob` | Suppressed live production sync in unsegmented installations (0 access groups) and caused regression in `DeviceManagementTest` | `app/Jobs/SyncPersonnelJob.php`, `app/Observers/PersonnelObserver.php` |
| 1 | Fallback check using `!AccessGroup::where('is_active', true)->exists()` | Granted company-wide camera access when all access groups were deactivated, violating contract | `app/Services/AccessControlService.php` |
| 1 | Hardcoded `ilike` in search filter | Non-portable SQL syntax error under non-PostgreSQL / SQLite test runners | `app/Http/Controllers/AccessGroupController.php` |
