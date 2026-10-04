<?php

namespace App\Http\Controllers;

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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization_id' => 'nullable|exists:organizations,id',
            'name' => 'required|string|max:128',
            'code' => [
                'required',
                'string',
                'max:64',
                Rule::unique('shifts')->where(function ($query) use ($request) {
                    $orgId = $request->input('organization_id');
                    return $orgId ? $query->where('organization_id', $orgId) : $query->whereNull('organization_id');
                }),
            ],
            'shift_start' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
            'shift_end' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
            'grace_period_minutes' => 'nullable|integer|min:0',
            'early_out_threshold_minutes' => 'nullable|integer|min:0',
            'half_day_threshold_hours' => 'nullable|numeric|min:0',
            'min_hours_full_day' => 'nullable|numeric|min:0',
            'is_overnight' => 'nullable|boolean',
            'break_duration_minutes' => 'nullable|integer|min:0',
            'is_flexible' => 'nullable|boolean',
            'color' => 'nullable|string|max:32',
            'is_active' => 'nullable|boolean',
        ]);

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

    public function update(Request $request, int $id): JsonResponse
    {
        $shift = Shift::findOrFail($id);

        $validated = $request->validate([
            'organization_id' => 'nullable|exists:organizations,id',
            'name' => 'sometimes|required|string|max:128',
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:64',
                Rule::unique('shifts')->where(function ($query) use ($request, $shift) {
                    $orgId = $request->input('organization_id', $shift->organization_id);
                    return $orgId ? $query->where('organization_id', $orgId) : $query->whereNull('organization_id');
                })->ignore($shift->id),
            ],
            'shift_start' => ['sometimes', 'required', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
            'shift_end' => ['sometimes', 'required', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
            'grace_period_minutes' => 'nullable|integer|min:0',
            'early_out_threshold_minutes' => 'nullable|integer|min:0',
            'half_day_threshold_hours' => 'nullable|numeric|min:0',
            'min_hours_full_day' => 'nullable|numeric|min:0',
            'is_overnight' => 'nullable|boolean',
            'break_duration_minutes' => 'nullable|integer|min:0',
            'is_flexible' => 'nullable|boolean',
            'color' => 'nullable|string|max:32',
            'is_active' => 'nullable|boolean',
        ]);

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
    public function assign(Request $request, int $id): JsonResponse
    {
        $shift = Shift::findOrFail($id);

        $validated = $request->validate([
            'employee_ids' => 'required|array',
            'employee_ids.*' => 'exists:employees,id',
            'effective_from' => 'required|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'assigned_days' => 'nullable|array',
        ]);

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
    public function bulkAssign(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'shift_id' => 'required|exists:shifts,id',
            'employee_ids' => 'nullable|array',
            'employee_ids.*' => 'exists:employees,id',
            'department_ids' => 'nullable|array',
            'department_ids.*' => 'exists:departments,id',
            'effective_from' => 'required|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'assigned_days' => 'nullable|array',
        ]);

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
            foreach ($employeeIds as $employeeId) {
                // Intelligent shift rotation capping: cap previous open-ended assignments
                EmployeeShiftAssignment::where('employee_id', $employeeId)
                    ->where('effective_from', '<=', $effectiveFrom)
                    ->whereNull('effective_to')
                    ->update(['effective_to' => $prevEndDate]);

                $assignment = EmployeeShiftAssignment::create([
                    'employee_id' => $employeeId,
                    'shift_id' => $shift->id,
                    'effective_from' => $effectiveFrom,
                    'effective_to' => $effectiveTo,
                    'assigned_days' => $assignedDays,
                    'created_by' => $userId,
                ]);

                // Update default active shift on employee record
                Employee::where('id', $employeeId)->update(['shift_id' => $shift->id]);
                $assignments[] = $assignment;
            }
        });

        return $assignments;
    }
}
