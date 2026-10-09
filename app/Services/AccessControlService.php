<?php

namespace App\Services;

use App\Jobs\SyncDevicePersonnelJob;
use App\Models\AccessGroup;
use App\Models\Department;
use App\Models\Device;
use App\Models\Personnel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AccessControlService
{
    /**
     * Resolve all authorized edge devices for a personnel member.
     *
     * Fallback behavior:
     * - If no access groups exist in the system (AccessGroup::count() === 0), returns all active devices (unsegmented fallback).
     * - If access groups exist, resolves direct group memberships and departmental memberships for active groups only.
     * - If the personnel member has no matching active groups (or all groups are inactive), returns an empty collection.
     */
    public function getAuthorizedDevicesForPersonnel(Personnel $personnel): Collection
    {
        if (!Schema::hasTable('access_groups') || AccessGroup::count() === 0) {
            return Device::where('is_active', true)->get();
        }

        // 1. Direct active access group memberships
        $directGroupIds = $personnel->accessGroups()
            ->where('access_groups.is_active', true)
            ->pluck('access_groups.id');

        // 2. Departmental active access group memberships
        $deptGroupIds = collect();
        $employee = $personnel->relationLoaded('employee') ? $personnel->employee : $personnel->employee()->first();
        if ($employee && $employee->department_id) {
            $department = $employee->relationLoaded('department') ? $employee->department : Department::find($employee->department_id);
            if ($department) {
                $deptIds = collect([$department->id]);
                if (method_exists($department, 'getAncestors')) {
                    $deptIds = $deptIds->merge($department->getAncestors()->pluck('id'));
                }

                $deptGroupIds = DB::table('access_group_department')
                    ->join('access_groups', 'access_groups.id', '=', 'access_group_department.access_group_id')
                    ->whereIn('access_group_department.department_id', $deptIds)
                    ->where('access_groups.is_active', true)
                    ->pluck('access_groups.id');
            }
        }

        $allGroupIds = $directGroupIds->merge($deptGroupIds)->unique();

        if ($allGroupIds->isEmpty()) {
            return collect();
        }

        $deviceIds = DB::table('access_group_device')
            ->whereIn('access_group_id', $allGroupIds)
            ->pluck('device_id')
            ->unique();

        if ($deviceIds->isEmpty()) {
            return collect();
        }

        return Device::where('is_active', true)
            ->whereIn('id', $deviceIds)
            ->get();
    }

    /**
     * Resolve all authorized personnel members for a specific access group.
     * Includes direct personnel and departmental personnel.
     */
    public function getAuthorizedPersonnelForGroup(AccessGroup $group): Collection
    {
        $directPersonnel = $group->personnel()->get();

        $deptIds = $group->departments()->pluck('departments.id');
        $deptPersonnel = collect();
        if ($deptIds->isNotEmpty()) {
            $allDeptIds = collect($deptIds);
            foreach ($group->departments as $dept) {
                if (method_exists($dept, 'getDescendantIds')) {
                    $allDeptIds = $allDeptIds->merge($dept->getDescendantIds());
                }
            }
            $allDeptIds = $allDeptIds->unique();

            $deptPersonnel = Personnel::whereHas('employee', function ($q) use ($allDeptIds) {
                $q->whereIn('department_id', $allDeptIds);
            })->get();
        }

        return $directPersonnel->merge($deptPersonnel)->unique('id')->values();
    }

    /**
     * Dispatches synchronization of all authorized personnel to all active devices in the group.
     */
    public function syncZone(AccessGroup $group): array
    {
        $devices = $group->devices()->where('is_active', true)->get();
        $personnel = $this->getAuthorizedPersonnelForGroup($group);

        $dispatched = 0;
        foreach ($devices as $device) {
            foreach ($personnel as $person) {
                SyncDevicePersonnelJob::dispatch(
                    $device->id,
                    $person->id,
                    'ADD',
                    $person->customize_id
                );
                $dispatched++;
            }
        }

        return [
            'devices_count' => $devices->count(),
            'personnel_count' => $personnel->count(),
            'dispatched_jobs' => $dispatched,
        ];
    }
}
