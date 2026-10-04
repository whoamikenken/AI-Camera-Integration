<?php

namespace App\Http\Controllers;

use App\Models\Holiday;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HolidayController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Holiday::with('organization');

        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->query('organization_id'));
        }

        if ($request->filled('year')) {
            $year = (int) $request->query('year');
            $query->where(function ($q) use ($year) {
                $q->whereYear('date', $year)
                  ->orWhere('is_recurring', true);
            });
        }

        if ($request->filled('month')) {
            $month = (int) $request->query('month');
            $query->where(function ($q) use ($month) {
                $q->whereMonth('date', $month);
            });
        }

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where('name', 'like', "%{$search}%");
        }

        return response()->json($query->orderBy('date')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization_id' => 'nullable|exists:organizations,id',
            'name' => 'required|string|max:128',
            'date' => 'required|date',
            'type' => 'nullable|string|in:public,company,optional',
            'is_recurring' => 'nullable|boolean',
            'applies_to' => 'nullable|array',
        ]);

        if (empty($validated['type'])) {
            $validated['type'] = 'public';
        }

        $holiday = Holiday::create($validated);

        $year = \Carbon\Carbon::parse($holiday->date)->year;
        \Illuminate\Support\Facades\Cache::forget("holidays_{$year}");

        return response()->json([
            'message' => 'Holiday created successfully.',
            'data' => $holiday,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $holiday = Holiday::with('organization')->findOrFail($id);

        return response()->json(['data' => $holiday]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $holiday = Holiday::findOrFail($id);

        $validated = $request->validate([
            'organization_id' => 'nullable|exists:organizations,id',
            'name' => 'sometimes|required|string|max:128',
            'date' => 'sometimes|required|date',
            'type' => 'nullable|string|in:public,company,optional',
            'is_recurring' => 'nullable|boolean',
            'applies_to' => 'nullable|array',
        ]);

        $year = \Carbon\Carbon::parse($holiday->date)->year;
        \Illuminate\Support\Facades\Cache::forget("holidays_{$year}");
        $holiday->update($validated);
        $newYear = \Carbon\Carbon::parse($holiday->date)->year;
        if ($newYear !== $year) {
            \Illuminate\Support\Facades\Cache::forget("holidays_{$newYear}");
        }

        return response()->json([
            'message' => 'Holiday updated successfully.',
            'data' => $holiday,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $holiday = Holiday::findOrFail($id);
        $year = \Carbon\Carbon::parse($holiday->date)->year;
        \Illuminate\Support\Facades\Cache::forget("holidays_{$year}");
        
        $holiday->delete();

        return response()->json([
            'message' => 'Holiday deleted successfully.',
        ]);
    }
}
