<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Visit;
use App\Models\Visitor;
use App\Services\VisitorSyncService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class VisitorController extends Controller
{
    public function __construct(
        protected VisitorSyncService $visitorSyncService
    ) {}

    // =========================================================================
    // Visitor Profiles
    // =========================================================================

    public function index(Request $request): JsonResponse
    {
        $query = Visitor::with('organization');

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('company', 'like', "%{$search}%")
                  ->orWhere('id_number', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_blocked')) {
            $query->where('is_blocked', filter_var($request->query('is_blocked'), FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = (int) $request->query('per_page', 20);
        return response()->json($query->orderBy('first_name')->paginate($perPage));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization_id' => 'nullable|exists:organizations,id',
            'first_name' => 'required|string|max:64',
            'last_name' => 'nullable|string|max:64',
            'email' => 'nullable|email|max:128',
            'phone' => 'nullable|string|max:32',
            'company' => 'nullable|string|max:128',
            'id_type' => 'nullable|string|max:32',
            'id_number' => 'nullable|string|max:64',
            'photo_path' => 'nullable|string|max:255',
            'is_blocked' => 'nullable|boolean',
            'block_reason' => 'nullable|string|max:500',
        ]);

        $visitor = Visitor::create($validated);

        return response()->json([
            'message' => 'Visitor profile created successfully.',
            'data' => $visitor,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $visitor = Visitor::with(['visits.host', 'visits.personnel'])->findOrFail($id);

        return response()->json(['data' => $visitor]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $visitor = Visitor::findOrFail($id);

        $validated = $request->validate([
            'organization_id' => 'nullable|exists:organizations,id',
            'first_name' => 'sometimes|required|string|max:64',
            'last_name' => 'nullable|string|max:64',
            'email' => 'nullable|email|max:128',
            'phone' => 'nullable|string|max:32',
            'company' => 'nullable|string|max:128',
            'id_type' => 'nullable|string|max:32',
            'id_number' => 'nullable|string|max:64',
            'photo_path' => 'nullable|string|max:255',
            'is_blocked' => 'nullable|boolean',
            'block_reason' => 'nullable|string|max:500',
        ]);

        $visitor->update($validated);

        return response()->json([
            'message' => 'Visitor profile updated successfully.',
            'data' => $visitor,
        ]);
    }

    public function block(Request $request, int $id): JsonResponse
    {
        $visitor = Visitor::findOrFail($id);

        $validated = $request->validate([
            'is_blocked' => 'required|boolean',
            'block_reason' => 'nullable|string|max:500',
        ]);

        $visitor->update($validated);

        return response()->json([
            'message' => $visitor->is_blocked ? 'Visitor added to security watchlist.' : 'Visitor removed from security watchlist.',
            'data' => $visitor,
        ]);
    }

    // =========================================================================
    // Visits Lifecycle
    // =========================================================================

    public function listVisits(Request $request): JsonResponse
    {
        $query = Visit::with(['visitor', 'host', 'personnel']);

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('visitor_id')) {
            $query->where('visitor_id', $request->query('visitor_id'));
        }

        if ($request->filled('host_employee_id')) {
            $query->where('host_employee_id', $request->query('host_employee_id'));
        }

        if ($request->filled('date')) {
            $query->whereDate('expected_arrival', $request->query('date'));
        }

        $perPage = (int) $request->query('per_page', 20);
        return response()->json($query->orderBy('expected_arrival', 'desc')->paginate($perPage));
    }

    public function preRegister(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'visitor_id' => 'required',
            'host_employee_id' => 'nullable',
            'purpose' => 'nullable|string|max:64',
            'purpose_detail' => 'nullable|string|max:500',
            'expected_arrival' => 'nullable|date',
        ]);

        $visitor = Visitor::find($validated['visitor_id']);
        if (!$visitor) {
            return response()->json([
                'message' => 'Visitor not found.',
            ], 404);
        }

        if ($visitor->is_blocked) {
            return response()->json([
                'message' => 'Visitor is on security watchlist and cannot be scheduled.',
            ], 403);
        }

        $hostId = $validated['host_employee_id'] ?? null;
        if ($hostId) {
            $host = Employee::findOrFail($hostId);
            $hostId = $host->id;
        }

        $visit = Visit::create([
            'visitor_id' => $visitor->id,
            'host_employee_id' => $hostId,
            'purpose' => $validated['purpose'] ?? 'meeting',
            'purpose_detail' => $validated['purpose_detail'] ?? null,
            'expected_arrival' => $validated['expected_arrival'] ?? now(),
            'status' => 'expected',
        ]);

        return response()->json([
            'message' => 'Visit pre-registered successfully.',
            'data' => $visit->load(['visitor', 'host']),
        ], 201);
    }

    public function checkIn(Request $request, int $id): JsonResponse
    {
        $visit = Visit::findOrFail($id);

        $validated = $request->validate([
            'badge_number' => 'nullable|string|max:64',
            'nda_signed' => 'nullable|boolean',
        ]);

        try {
            $checkedIn = $this->visitorSyncService->checkIn($visit, $validated);

            return response()->json([
                'message' => 'Visitor checked in successfully. Biometric access provisioned.',
                'data' => $checkedIn,
            ]);
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    public function checkOut(Request $request, int $id): JsonResponse
    {
        $visit = Visit::find($id);
        if (!$visit) {
            return response()->json([
                'message' => 'Visit not found.',
            ], 404);
        }

        if ($visit->status === 'checked_out') {
            return response()->json([
                'message' => 'Visit is already checked out.',
            ], 422);
        }

        $checkedOut = $this->visitorSyncService->checkOut($visit);

        return response()->json([
            'message' => 'Visitor checked out successfully. Biometric access revoked.',
            'data' => $checkedOut,
        ]);
    }
}
