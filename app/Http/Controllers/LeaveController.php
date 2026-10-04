<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\LeaveService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LeaveController extends Controller
{
    public function __construct(
        protected LeaveService $leaveService
    ) {}

    // =========================================================================
    // Leave Types
    // =========================================================================

    public function listLeaveTypes(Request $request): JsonResponse
    {
        $query = LeaveType::with('organization');

        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->query('organization_id'));
        }

        return response()->json($query->orderBy('name')->get());
    }

    public function storeLeaveType(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization_id' => 'nullable|exists:organizations,id',
            'name' => 'required|string|max:128',
            'code' => 'required|string|max:64|unique:leave_types,code',
            'max_days_per_year' => 'nullable|numeric|min:0',
            'is_paid' => 'nullable|boolean',
            'is_carry_forward' => 'nullable|boolean',
            'max_carry_forward_days' => 'nullable|numeric|min:0',
            'description' => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
        ]);

        $leaveType = LeaveType::create($validated);

        return response()->json([
            'message' => 'Leave type created successfully.',
            'data' => $leaveType,
        ], 201);
    }

    public function showLeaveType(int $id): JsonResponse
    {
        return response()->json(['data' => LeaveType::findOrFail($id)]);
    }

    public function updateLeaveType(Request $request, int $id): JsonResponse
    {
        $leaveType = LeaveType::findOrFail($id);

        $validated = $request->validate([
            'organization_id' => 'nullable|exists:organizations,id',
            'name' => 'sometimes|required|string|max:128',
            'code' => 'sometimes|required|string|max:64|unique:leave_types,code,' . $leaveType->id,
            'max_days_per_year' => 'nullable|numeric|min:0',
            'is_paid' => 'nullable|boolean',
            'is_carry_forward' => 'nullable|boolean',
            'max_carry_forward_days' => 'nullable|numeric|min:0',
            'description' => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
        ]);

        $leaveType->update($validated);

        return response()->json([
            'message' => 'Leave type updated successfully.',
            'data' => $leaveType,
        ]);
    }

    public function destroyLeaveType(int $id): JsonResponse
    {
        $leaveType = LeaveType::findOrFail($id);
        $leaveType->delete();

        return response()->json(['message' => 'Leave type deleted successfully.']);
    }

    // =========================================================================
    // Leave Balances
    // =========================================================================

    public function listBalances(Request $request): JsonResponse
    {
        $query = LeaveBalance::with(['employee', 'leaveType']);

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->query('employee_id'));
        }

        if ($request->filled('year')) {
            $query->where('year', $request->query('year'));
        }

        return response()->json($query->get());
    }

    public function allocateBalance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required',
            'leave_type_id' => 'required',
            'year' => 'required|integer|min:2000',
            'allocated' => 'required|numeric|min:0',
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);
        $leaveType = LeaveType::findOrFail($validated['leave_type_id']);

        $balance = $this->leaveService->allocateBalance(
            employee: $employee,
            leaveTypeId: $leaveType->id,
            year: (int) $validated['year'],
            allocatedDays: (float) $validated['allocated']
        );

        return response()->json([
            'message' => 'Leave balance allocated successfully.',
            'data' => $balance->load(['employee', 'leaveType']),
        ], 200);
    }

    // =========================================================================
    // Leave Requests
    // =========================================================================

    public function listRequests(Request $request): JsonResponse
    {
        $query = LeaveRequest::with(['employee', 'leaveType', 'approver']);

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->query('employee_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $perPage = (int) $request->query('per_page', 15);
        return response()->json($query->orderBy('created_at', 'desc')->paginate($perPage));
    }

    public function storeRequest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required',
            'leave_type_id' => 'required',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'nullable|string|max:500',
        ]);

        $user = $request->user();
        $canManageLeaves = $user && ($user->hasRole(['super-admin', 'admin', 'hr-manager']) || $user->hasPermission('leaves.manage'));

        if (!$canManageLeaves && $user) {
            $userEmployee = $user->employee;
            if (!$userEmployee) {
                return response()->json([
                    'message' => 'User is not associated with an active employee record.',
                ], 403);
            }
            if ((int) $validated['employee_id'] !== (int) $userEmployee->id) {
                return response()->json([
                    'message' => 'You cannot submit leave requests for other employees.',
                ], 403);
            }
            $employee = $userEmployee;
        } else {
            $employee = Employee::findOrFail($validated['employee_id']);
        }

        $leaveType = LeaveType::findOrFail($validated['leave_type_id']);

        $leaveRequest = $this->leaveService->submitLeaveRequest(
            employee: $employee,
            leaveTypeId: $leaveType->id,
            startDate: $validated['start_date'],
            endDate: $validated['end_date'],
            reason: $validated['reason'] ?? null
        );

        return response()->json([
            'message' => 'Leave request submitted successfully.',
            'data' => $leaveRequest->load(['employee', 'leaveType']),
        ], 201);
    }

    public function approveRequest(Request $request, int $id): JsonResponse
    {
        $leaveRequest = LeaveRequest::findOrFail($id);

        if ($leaveRequest->employee && $leaveRequest->employee->user_id && (int) $leaveRequest->employee->user_id === (int) $request->user()?->id) {
            return response()->json([
                'message' => 'Self-approval of leave requests is forbidden.',
            ], 403);
        }

        if ($leaveRequest->status !== 'pending') {
            return response()->json([
                'message' => "Cannot approve leave request with status '{$leaveRequest->status}'.",
            ], 422);
        }

        $approved = $this->leaveService->approveLeaveRequest($leaveRequest, $request->user());

        return response()->json([
            'message' => 'Leave request approved successfully.',
            'data' => $approved,
        ]);
    }

    public function rejectRequest(Request $request, int $id): JsonResponse
    {
        $leaveRequest = LeaveRequest::findOrFail($id);

        if ($leaveRequest->status !== 'pending') {
            return response()->json([
                'message' => "Cannot reject leave request with status '{$leaveRequest->status}'.",
            ], 422);
        }

        $reason = $request->input('reason', 'Rejected by manager');
        $rejected = $this->leaveService->rejectLeaveRequest($leaveRequest, $reason, $request->user());

        return response()->json([
            'message' => 'Leave request rejected.',
            'data' => $rejected,
        ]);
    }
}
