<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\RegularizationRequest;
use App\Services\AttendanceProcessingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RegularizationController extends Controller
{
    public function __construct(
        protected AttendanceProcessingService $attendanceService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = RegularizationRequest::with(['employee.department', 'approver']);

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->query('employee_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $perPage = (int) $request->query('per_page', 15);
        return response()->json($query->orderBy('created_at', 'desc')->paginate($perPage));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required',
            'date' => 'required|date',
            'requested_in' => 'nullable|date',
            'requested_out' => 'nullable|date',
            'reason' => 'required|string|max:500',
        ]);

        $dateObj = Carbon::parse($validated['date']);
        if ($dateObj->isFuture()) {
            throw ValidationException::withMessages([
                'date' => ['Attendance regularization cannot be requested for future dates.'],
            ]);
        }

        $user = $request->user();
        $canManageAttendance = $user && ($user->hasRole(['super-admin', 'admin', 'hr-manager']) || $user->hasPermission('attendance.manage'));

        if (!$canManageAttendance && $user) {
            $userEmployee = $user->employee;
            if (!$userEmployee) {
                return response()->json([
                    'message' => 'User is not associated with an active employee record.',
                ], 403);
            }
            if ((int) $validated['employee_id'] !== (int) $userEmployee->id) {
                return response()->json([
                    'message' => 'You cannot submit regularization requests for other employees.',
                ], 403);
            }
            $employee = $userEmployee;
        } else {
            $employee = Employee::findOrFail($validated['employee_id']);
        }

        $regularization = RegularizationRequest::create([
            'employee_id' => $employee->id,
            'date' => $validated['date'],
            'requested_in' => $validated['requested_in'] ?? null,
            'requested_out' => $validated['requested_out'] ?? null,
            'reason' => $validated['reason'],
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Regularization request submitted successfully.',
            'data' => $regularization->load('employee'),
        ], 201);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $regularization = RegularizationRequest::findOrFail($id);

        if ($regularization->employee && $regularization->employee->user_id && (int) $regularization->employee->user_id === (int) $request->user()?->id) {
            return response()->json([
                'message' => 'Self-approval of regularization requests is forbidden.',
            ], 403);
        }

        if ($regularization->status !== 'pending') {
            return response()->json([
                'message' => "Cannot approve regularization with status '{$regularization->status}'.",
            ], 422);
        }

        $employee = $regularization->employee;

        // Process corrected punches
        if ($regularization->requested_in) {
            $this->attendanceService->processPunch(
                employee: $employee,
                punchTime: $regularization->requested_in,
                direction: 'in',
                source: 'regularized',
                reason: 'Regularized: ' . $regularization->reason
            );
        }

        if ($regularization->requested_out) {
            $this->attendanceService->processPunch(
                employee: $employee,
                punchTime: $regularization->requested_out,
                direction: 'out',
                source: 'regularized',
                reason: 'Regularized: ' . $regularization->reason
            );
        }

        $this->attendanceService->recalculateDailyAttendance($employee, $regularization->date);

        $regularization->update([
            'status' => 'approved',
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
        ]);

        return response()->json([
            'message' => 'Regularization request approved.',
            'data' => $regularization->fresh(),
        ]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $regularization = RegularizationRequest::findOrFail($id);

        if ($regularization->status !== 'pending') {
            return response()->json([
                'message' => "Cannot reject regularization with status '{$regularization->status}'.",
            ], 422);
        }

        $reason = $request->input('reason', 'Rejected by manager');
        $regularization->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'approved_by' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'Regularization request rejected.',
            'data' => $regularization,
        ]);
    }
}
