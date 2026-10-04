<?php

namespace App\Http\Controllers;

use App\Jobs\DailyAttendanceFinalizerJob;
use App\Models\AttendancePunch;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Services\AttendanceProcessingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function __construct(
        protected AttendanceProcessingService $attendanceService
    ) {}

    /**
     * Daily attendance roster & status overview.
     */
    public function daily(Request $request): JsonResponse
    {
        $date = $request->query('date', Carbon::today()->toDateString());

        $query = AttendanceRecord::with(['employee.department', 'employee.designation', 'employee.location', 'shift'])
            ->where('date', $date);

        if ($request->filled('department_id')) {
            $query->whereHas('employee', fn($q) => $q->where('department_id', $request->query('department_id')));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $records = $query->get();

        // Count summary metrics
        $summary = [
            'total' => $records->count(),
            'present' => $records->whereIn('status', ['present', 'late', 'early_out', 'late_and_early_out'])->count(),
            'late' => $records->whereIn('status', ['late', 'late_and_early_out'])->count(),
            'early_out' => $records->whereIn('status', ['early_out', 'late_and_early_out'])->count(),
            'absent' => $records->where('status', 'absent')->count(),
            'half_day' => $records->where('status', 'half_day')->count(),
            'on_leave' => $records->where('status', 'on_leave')->count(),
            'holiday' => $records->where('status', 'holiday')->count(),
        ];

        return response()->json([
            'date' => $date,
            'summary' => $summary,
            'records' => $records,
        ]);
    }

    /**
     * Paginated historical attendance records.
     */
    public function records(Request $request): JsonResponse
    {
        $query = AttendanceRecord::with(['employee.department', 'employee.designation', 'shift']);

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->query('employee_id'));
        }

        if ($request->filled('from_date')) {
            $query->where('date', '>=', $request->query('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->where('date', '<=', $request->query('to_date'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $perPage = (int) $request->query('per_page', 20);
        return response()->json($query->orderBy('date', 'desc')->paginate($perPage));
    }

    /**
     * List raw biometric and manual punches.
     */
    public function punches(Request $request): JsonResponse
    {
        $query = AttendancePunch::with(['employee', 'device', 'accessLog']);

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->query('employee_id'));
        }

        if ($request->filled('date')) {
            $query->whereDate('punch_time', $request->query('date'));
        }

        if ($request->filled('direction')) {
            $query->where('direction', $request->query('direction'));
        }

        $perPage = (int) $request->query('per_page', 30);
        return response()->json($query->orderBy('punch_time', 'desc')->paginate($perPage));
    }

    /**
     * HR manual punch entry.
     */
    public function manualEntry(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required',
            'punch_time' => 'required|date',
            'direction' => 'required|string|in:in,out',
            'reason' => 'required|string|max:255',
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);

        $punch = $this->attendanceService->processPunch(
            employee: $employee,
            punchTime: $validated['punch_time'],
            direction: $validated['direction'],
            source: 'manual',
            reason: $validated['reason']
        );

        return response()->json([
            'message' => 'Attendance punch recorded successfully.',
            'data' => $punch,
        ], 200);
    }

    /**
     * HR attendance status/hours override.
     */
    public function override(Request $request, int $id): JsonResponse
    {
        $record = AttendanceRecord::findOrFail($id);

        $validated = $request->validate([
            'status' => 'sometimes|required|string|in:present,absent,late,early_out,late_and_early_out,half_day,on_leave,holiday,weekend',
            'total_work_hours' => 'nullable|numeric|min:0',
            'overtime_hours' => 'nullable|numeric|min:0',
            'is_late' => 'nullable|boolean',
            'late_minutes' => 'nullable|integer|min:0',
            'is_early_out' => 'nullable|boolean',
            'early_out_minutes' => 'nullable|integer|min:0',
            'remarks' => 'required|string|max:500',
        ]);

        $validated['source'] = 'manual';
        $record->update($validated);

        return response()->json([
            'message' => 'Attendance record overridden successfully.',
            'data' => $record->fresh(),
        ]);
    }

    /**
     * Trigger daily attendance finalization.
     */
    public function finalizeDaily(Request $request): JsonResponse
    {
        $date = $request->input('date', Carbon::today()->toDateString());
        DailyAttendanceFinalizerJob::dispatch(Carbon::parse($date));

        return response()->json([
            'message' => "Daily attendance finalization job dispatched for {$date}.",
        ]);
    }
}
