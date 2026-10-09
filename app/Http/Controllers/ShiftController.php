<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignShiftRequest;
use App\Http\Requests\BulkAssignShiftRequest;
use App\Http\Requests\StoreShiftRequest;
use App\Http\Requests\UpdateShiftRequest;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\Shift;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ShiftController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Shift::with(['organization'])->withCount('employees');

        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->query('organization_id'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        return response()->json($query->orderBy('name')->get());
    }

    public function store(StoreShiftRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $isOvernight = !empty($validated['is_overnight']);
        $startNorm = strlen($validated['shift_start']) === 5 ? $validated['shift_start'] . ':00' : $validated['shift_start'];
        $endNorm = strlen($validated['shift_end']) === 5 ? $validated['shift_end'] . ':00' : $validated['shift_end'];

        if (!$isOvernight && $startNorm === $endNorm) {
            throw ValidationException::withMessages([
                'shift_end' => ['Shift start time and end time cannot be identical for non-overnight shifts.'],
            ]);
        }

        $validated['shift_start'] = $startNorm;
        $validated['shift_end'] = $endNorm;

        $shift = Shift::create($validated);

        return response()->json([
            'message' => 'Shift created successfully.',
            'data' => $shift,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $shift = Shift::with(['organization', 'employees'])->findOrFail($id);

        return response()->json(['data' => $shift]);
    }

    public function update(UpdateShiftRequest $request, int $id): JsonResponse
    {
        $shift = Shift::findOrFail($id);

        $validated = $request->validated();

        $start = $validated['shift_start'] ?? $shift->shift_start;
        $end = $validated['shift_end'] ?? $shift->shift_end;
        $isOvernight = isset($validated['is_overnight']) ? (bool) $validated['is_overnight'] : (bool) $shift->is_overnight;

        $startNorm = strlen($start) === 5 ? $start . ':00' : $start;
        $endNorm = strlen($end) === 5 ? $end . ':00' : $end;

        if (!$isOvernight && $startNorm === $endNorm) {
            throw ValidationException::withMessages([
                'shift_end' => ['Shift start time and end time cannot be identical for non-overnight shifts.'],
            ]);
        }

        if (isset($validated['shift_start'])) {
            $validated['shift_start'] = $startNorm;
        }
        if (isset($validated['shift_end'])) {
            $validated['shift_end'] = $endNorm;
        }

        $shift->update($validated);

        return response()->json([
            'message' => 'Shift updated successfully.',
            'data' => $shift,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $shift = Shift::findOrFail($id);
        $shift->delete();

        return response()->json([
            'message' => 'Shift deleted successfully.',
        ]);
    }

    // Direct assignment to specific employee IDs
    public function assign(AssignShiftRequest $request, int $id): JsonResponse
    {
        $shift = Shift::findOrFail($id);

        $validated = $request->validated();

        $assignments = $this->performShiftAssignment(
            $shift,
            $validated['employee_ids'],
            $validated['effective_from'],
            $validated['effective_to'] ?? null,
            $validated['assigned_days'] ?? null,
            $request->user()?->id
        );

        return response()->json([
            'message' => 'Shift assigned to ' . count($assignments) . ' employees.',
            'data' => $assignments,
        ], 201);
    }

    // Bulk assign endpoint supporting employees or entire departments
    public function bulkAssign(BulkAssignShiftRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $shift = Shift::findOrFail($validated['shift_id']);
        $targetIds = $validated['employee_ids'] ?? [];

        if (!empty($validated['department_ids'])) {
            $deptEmpIds = Employee::whereIn('department_id', $validated['department_ids'])
                ->where('employment_status', 'active')
                ->pluck('id')
                ->toArray();
            $targetIds = array_values(array_unique(array_merge($targetIds, $deptEmpIds)));
        }

        if (empty($targetIds)) {
            return response()->json([
                'message' => 'No eligible employees found for assignment.',
            ], 422);
        }

        $assignments = $this->performShiftAssignment(
            $shift,
            $targetIds,
            $validated['effective_from'],
            $validated['effective_to'] ?? null,
            $validated['assigned_days'] ?? null,
            $request->user()?->id
        );

        return response()->json([
            'message' => 'Shift assigned to ' . count($assignments) . ' employees.',
            'count' => count($assignments),
            'data' => $assignments,
        ], 201);
    }

    protected function performShiftAssignment(
        Shift $shift,
        array $employeeIds,
        string $effectiveFrom,
        ?string $effectiveTo,
        ?array $assignedDays,
        ?int $userId
    ): array {
        $assignments = [];
        $effFromDate = Carbon::parse($effectiveFrom);
        $prevEndDate = $effFromDate->copy()->subDay()->toDateString();

        DB::transaction(function () use (
            $shift,
            $employeeIds,
            $effectiveFrom,
            $effectiveTo,
            $assignedDays,
            $userId,
            $prevEndDate,
            &$assignments
        ) {
            // Intelligent shift rotation capping: cap previous open-ended assignments in bulk
            EmployeeShiftAssignment::whereIn('employee_id', $employeeIds)
                ->where('effective_from', '<=', $effectiveFrom)
                ->whereNull('effective_to')
                ->update(['effective_to' => $prevEndDate]);

            $rows = [];
            $now = now();
            $assignedDaysJson = $assignedDays !== null ? json_encode($assignedDays) : null;
            
            foreach ($employeeIds as $employeeId) {
                $rows[] = [
                    'employee_id' => $employeeId,
                    'shift_id' => $shift->id,
                    'effective_from' => $effectiveFrom,
                    'effective_to' => $effectiveTo,
                    'assigned_days' => $assignedDaysJson,
                    'created_by' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // Bulk insert new assignments
            EmployeeShiftAssignment::insert($rows);

            // Update default active shift on employee record in bulk
            Employee::whereIn('id', $employeeIds)->update(['shift_id' => $shift->id]);
            
            // Retrieve created assignments to return
            $assignments = EmployeeShiftAssignment::where('shift_id', $shift->id)
                ->where('effective_from', $effectiveFrom)
                ->whereIn('employee_id', $employeeIds)
                ->where('created_at', $now)
                ->get()
                ->toArray();

            // Invalidate employee shift cache in O(1) without blocking Redis KEYS
            try {
                app(\App\Services\AttendanceProcessingService::class)->invalidateShiftCacheForEmployees($employeeIds);
            } catch (\Throwable $e) {
                // Ignore cache errors
            }
        });

        return $assignments;
    }
}
