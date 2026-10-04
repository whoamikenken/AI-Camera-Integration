<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CleanDuplicateTelemetryCommand extends Command
{
    protected $signature = 'telemetry:clean-duplicates {--dry-run : Only report duplicates without deleting}';
    protected $description = 'Clean up duplicate stranger snaps and access logs in the database caused by multi-daemon telemetry ingestion';

    public function handle(): void
    {
        $isDryRun = $this->option('dry-run');

        $this->info("Scanning for duplicate stranger snaps...");

        // 1. Clean Stranger Snaps with snap_id
        $strangerSnapDuplicates = DB::table('stranger_snaps')
            ->select('device_id', 'snap_id', 'captured_at', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as total_count'))
            ->whereNotNull('snap_id')
            ->groupBy('device_id', 'snap_id', 'captured_at')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $deletedSnapsCount = 0;

        foreach ($strangerSnapDuplicates as $group) {
            $duplicateIds = DB::table('stranger_snaps')
                ->where('device_id', $group->device_id)
                ->where('snap_id', $group->snap_id)
                ->where('captured_at', $group->captured_at)
                ->where('id', '>', $group->keep_id)
                ->pluck('id');

            $deletedSnapsCount += count($duplicateIds);

            if (!$isDryRun && count($duplicateIds) > 0) {
                DB::table('stranger_snaps')->whereIn('id', $duplicateIds)->delete();
            }
        }

        // 2. Clean Stranger Snaps without snap_id (fallback to target_pos::text + captured_at)
        $strangerSnapNullIdDuplicates = DB::table('stranger_snaps')
            ->select('device_id', 'captured_at', DB::raw('target_pos::text as target_pos_str'), DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as total_count'))
            ->whereNull('snap_id')
            ->groupBy('device_id', 'captured_at', DB::raw('target_pos::text'))
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($strangerSnapNullIdDuplicates as $group) {
            $duplicateIds = DB::table('stranger_snaps')
                ->where('device_id', $group->device_id)
                ->whereNull('snap_id')
                ->where('captured_at', $group->captured_at)
                ->whereRaw('target_pos::text = ?', [$group->target_pos_str])
                ->where('id', '>', $group->keep_id)
                ->pluck('id');

            $deletedSnapsCount += count($duplicateIds);

            if (!$isDryRun && count($duplicateIds) > 0) {
                DB::table('stranger_snaps')->whereIn('id', $duplicateIds)->delete();
            }
        }

        // 3. Clean Access Logs
        $this->info("Scanning for duplicate access logs...");

        $accessLogDuplicates = DB::table('access_logs')
            ->select('device_id', 'person_id', 'captured_at', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as total_count'))
            ->groupBy('device_id', 'person_id', 'captured_at')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $deletedLogsCount = 0;

        foreach ($accessLogDuplicates as $group) {
            $query = DB::table('access_logs')
                ->where('device_id', $group->device_id)
                ->where('captured_at', $group->captured_at)
                ->where('id', '>', $group->keep_id);

            if (is_null($group->person_id)) {
                $query->whereNull('person_id');
            } else {
                $query->where('person_id', $group->person_id);
            }

            $duplicateIds = $query->pluck('id');
            $deletedLogsCount += count($duplicateIds);

            if (!$isDryRun && count($duplicateIds) > 0) {
                DB::table('access_logs')->whereIn('id', $duplicateIds)->delete();
            }
        }

        $actionWord = $isDryRun ? "Found (Dry Run)" : "Deleted";
        $this->info("{$actionWord} {$deletedSnapsCount} duplicate stranger snaps and {$deletedLogsCount} duplicate access logs.");
        Log::info("CleanDuplicateTelemetryCommand executed: {$actionWord} {$deletedSnapsCount} duplicate stranger snaps, {$deletedLogsCount} access logs.");
    }
}
