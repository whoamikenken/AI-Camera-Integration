<?php

namespace App\Http\Controllers;

use App\Http\Requests\CancelVisitRequest;
use App\Http\Requests\CheckInVisitRequest;
use App\Http\Requests\CreateVisitRequest;
use App\Http\Requests\StoreVisitorRequest;
use App\Http\Requests\UpdateVisitorRequest;
use App\Models\Employee;
use App\Models\Visit;
use App\Models\Visitor;
use App\Services\VisitorSyncService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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

    public function store(StoreVisitorRequest $request): JsonResponse
    {
        $validated = $request->validated();

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

    public function update(UpdateVisitorRequest $request, int $id): JsonResponse
    {
        $visitor = Visitor::findOrFail($id);

        $validated = $request->validated();

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
            try {
                $date = Carbon::parse($request->query('date'));
                $startOfDay = $date->copy()->startOfDay();
                $endOfDay = $date->copy()->endOfDay();
                $query->whereBetween('expected_arrival', [$startOfDay, $endOfDay]);
            } catch (\Throwable) {
                $query->whereRaw('1 = 0');
            }
        }

        $perPage = (int) $request->query('per_page', 20);
        $paginated = $query->orderBy('expected_arrival', 'desc')->paginate($perPage);

        $stats = $this->calculateVisitorStats($request);
        $response = $paginated->toArray();
        $response['stats'] = $stats;
        $response['meta'] = [
            'stats' => $stats,
        ];

        return response()->json($response);
    }

    /**
     * Get facility-wide aggregate visitor statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        $stats = $this->calculateVisitorStats($request);

        return response()->json([
            'data' => $stats,
            'stats' => $stats,
            'meta' => [
                'stats' => $stats,
            ],
        ]);
    }

    /**
     * Calculate facility-wide visitor aggregate statistics.
     */
    protected function calculateVisitorStats(Request $request): array
    {
        $today = Carbon::today();
        $isDateFiltered = $request->filled('date');

        if ($isDateFiltered) {
            try {
                $targetDate = Carbon::parse($request->query('date'));
                $startOfDay = $targetDate->copy()->startOfDay();
                $endOfDay = $targetDate->copy()->endOfDay();
            } catch (\Throwable) {
                return [
                    'expected_today' => 0,
                    'checked_in' => 0,
                    'checked_out' => 0,
                    'overdue' => 0,
                    'no_show' => 0,
                    'total' => 0,
                ];
            }
        } else {
            $startOfDay = $today->copy()->startOfDay();
            $endOfDay = $today->copy()->endOfDay();
        }

        $now = Carbon::now();

        $expectedCount = Visit::where('status', 'expected')
            ->whereBetween('expected_arrival', [$startOfDay, $endOfDay])
            ->count();

        $checkedInCount = Visit::where('status', 'checked_in')->count();

        $checkedOutCount = Visit::where('status', 'checked_out')
            ->where(function ($q) use ($startOfDay, $endOfDay) {
                $q->whereBetween('check_out_time', [$startOfDay, $endOfDay])
                  ->orWhere(function ($sub) use ($startOfDay, $endOfDay) {
                      $sub->whereNull('check_out_time')
                          ->whereBetween('updated_at', [$startOfDay, $endOfDay]);
                  });
            })
            ->count();

        $overdueCount = Visit::where(function ($q) use ($now) {
            $q->where('status', 'overstayed')
              ->orWhere(function ($sub) use ($now) {
                  $sub->where('status', 'checked_in')
                      ->whereNotNull('expected_departure')
                      ->where('expected_departure', '<', $now);
              });
        })->count();

        $noShowCount = Visit::where('status', 'no_show')
            ->where(function ($q) use ($startOfDay, $endOfDay) {
                $q->whereBetween('expected_arrival', [$startOfDay, $endOfDay])
                  ->orWhereBetween('updated_at', [$startOfDay, $endOfDay]);
            })
            ->count();

        $totalCount = Visit::whereBetween('expected_arrival', [$startOfDay, $endOfDay])->count();

        return [
            'expected_today' => $expectedCount,
            'checked_in' => $checkedInCount,
            'checked_out' => $checkedOutCount,
            'overdue' => $overdueCount,
            'no_show' => $noShowCount,
            'total' => $totalCount,
        ];
    }

    public function preRegister(CreateVisitRequest $request): JsonResponse
    {
        $validated = $request->validated();

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

    public function checkIn(CheckInVisitRequest $request, int $id): JsonResponse
    {
        $visit = Visit::findOrFail($id);

        $validated = $request->validated();

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

    public function showVisit(int $id): JsonResponse
    {
        $visit = Visit::with(['visitor', 'host', 'personnel'])->findOrFail($id);
        return response()->json(['data' => $visit]);
    }

    public function cancel(CancelVisitRequest $request, int $id): JsonResponse
    {
        $visit = Visit::findOrFail($id);

        $validated = $request->validated();

        try {
            $rawReason = $validated['reason'] ?? $request->input('cancellation_reason');
            $reason = !empty(trim((string) $rawReason)) ? trim((string) $rawReason) : 'Cancelled by user';
            $cancelledVisit = $this->visitorSyncService->cancelVisit(
                $visit,
                $request->user(),
                $reason
            );

            return response()->json([
                'message' => 'Visit cancelled successfully. Biometric access revoked.',
                'data' => $cancelledVisit,
            ]);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    public function cancelVisit(CancelVisitRequest $request, int $id): JsonResponse
    {
        return $this->cancel($request, $id);
    }

    public function overstayed(Request $request): JsonResponse
    {
        $query = Visit::with(['visitor', 'host.department', 'personnel'])
            ->where(function ($q) {
                $q->where('status', 'overstayed')
                  ->orWhere(function ($sub) {
                      $sub->where('status', 'checked_in')
                          ->whereNotNull('expected_departure')
                          ->where('expected_departure', '<=', now()->subMinutes(15));
                  });
            })
            ->orderBy('expected_departure', 'asc');

        $perPage = (int) $request->query('per_page', 20);
        return response()->json($query->paginate($perPage));
    }
}
