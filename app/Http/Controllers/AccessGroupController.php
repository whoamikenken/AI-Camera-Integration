<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAccessGroupRequest;
use App\Http\Requests\UpdateAccessGroupRequest;
use App\Models\AccessGroup;
use App\Services\AccessControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccessGroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AccessGroup::query()
            ->with(['organization:id,name,code'])
            ->withCount(['devices', 'personnel', 'departments']);

        if ($request->filled('search')) {
            $search = $request->query('search');
            $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $query->where(function ($q) use ($search, $like) {
                $q->where('name', $like, "%{$search}%")
                  ->orWhere('code', $like, "%{$search}%")
                  ->orWhere('description', $like, "%{$search}%");
            });
        }

        if ($request->has('is_active') && $request->input('is_active') !== '') {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->query('organization_id'));
        }

        $query->orderBy('name');

        if ($request->boolean('all')) {
            return response()->json([
                'success' => true,
                'data' => $query->get(),
            ]);
        }

        return response()->json($query->paginate($request->input('per_page', 15)));
    }

    public function store(StoreAccessGroupRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $group = DB::transaction(function () use ($validated, $request) {
            $grp = AccessGroup::create([
                'name' => $validated['name'],
                'code' => $validated['code'],
                'description' => $validated['description'] ?? null,
                'organization_id' => $validated['organization_id'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
            ]);

            if ($request->has('device_ids')) {
                $grp->devices()->sync($request->input('device_ids', []));
            }
            if ($request->has('personnel_ids')) {
                $grp->personnel()->sync($request->input('personnel_ids', []));
            }
            if ($request->has('department_ids')) {
                $grp->departments()->sync($request->input('department_ids', []));
            }

            return $grp;
        });

        $group->load(['devices', 'personnel', 'departments', 'organization']);
        $group->loadCount(['devices', 'personnel', 'departments']);

        return response()->json([
            'success' => true,
            'message' => 'Access group created successfully.',
            'data' => $group,
            'id' => $group->id,
        ], 201);
    }

    public function show($id): JsonResponse
    {
        $group = AccessGroup::with(['devices', 'personnel', 'departments', 'organization'])
            ->withCount(['devices', 'personnel', 'departments'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $group,
            'id' => $group->id,
        ]);
    }

    public function update(UpdateAccessGroupRequest $request, $id): JsonResponse
    {
        $group = AccessGroup::findOrFail($id);

        $validated = $request->validated();

        DB::transaction(function () use ($group, $validated, $request) {
            $group->update(collect($validated)->only(['name', 'code', 'description', 'organization_id', 'is_active'])->toArray());

            if ($request->has('device_ids')) {
                $group->devices()->sync($request->input('device_ids', []));
            }
            if ($request->has('personnel_ids')) {
                $group->personnel()->sync($request->input('personnel_ids', []));
            }
            if ($request->has('department_ids')) {
                $group->departments()->sync($request->input('department_ids', []));
            }
        });

        $group->load(['devices', 'personnel', 'departments', 'organization']);
        $group->loadCount(['devices', 'personnel', 'departments']);

        return response()->json([
            'success' => true,
            'message' => 'Access group updated successfully.',
            'data' => $group,
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $group = AccessGroup::findOrFail($id);

        DB::transaction(function () use ($group) {
            $group->devices()->detach();
            $group->personnel()->detach();
            $group->departments()->detach();
            $group->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'Access group deleted successfully.',
        ]);
    }

    public function syncNow(Request $request, $id, ?AccessControlService $accessControlService = null): JsonResponse
    {
        $group = AccessGroup::with(['devices', 'personnel', 'departments'])->findOrFail($id);

        $service = $accessControlService ?? app(AccessControlService::class);
        $result = $service->syncZone($group);

        return response()->json([
            'success' => true,
            'message' => "Zone synchronization dispatched for {$result['devices_count']} device(s) and {$result['personnel_count']} personnel member(s).",
            'devices_count' => $result['devices_count'],
            'personnel_count' => $result['personnel_count'],
            'dispatched_count' => $result['dispatched_jobs'],
        ], 200);
    }
}
