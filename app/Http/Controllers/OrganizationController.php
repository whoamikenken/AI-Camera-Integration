<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Designation;
use App\Models\Location;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    private function resolveOrganizationId(?int $orgId = null): int
    {
        if ($orgId) {
            return $orgId;
        }

        $org = Organization::first();
        if ($org) {
            return $org->id;
        }

        $newOrg = Organization::create([
            'name' => 'Default Organization',
            'code' => 'DEFAULT-ORG',
            'timezone' => 'Asia/Manila',
            'is_active' => true,
        ]);

        return $newOrg->id;
    }

    // =========================================================================
    // Organizations Endpoints
    // =========================================================================

    public function index(): JsonResponse
    {
        $orgs = Organization::withCount(['locations', 'departments', 'devices', 'users'])->get();

        return response()->json([
            'success' => true,
            'data' => $orgs,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:128',
            'code' => 'required|string|max:64|unique:organizations,code',
            'logo' => 'nullable|string|max:255',
            'address' => 'nullable|string',
            'timezone' => 'nullable|string|max:64',
            'settings' => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ]);

        $org = Organization::create([
            'name' => $validated['name'],
            'code' => $validated['code'],
            'logo' => $validated['logo'] ?? null,
            'address' => $validated['address'] ?? null,
            'timezone' => $validated['timezone'] ?? 'Asia/Manila',
            'settings' => $validated['settings'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Organization created successfully.',
            'data' => $org,
        ], 201);
    }

    public function show(Organization $organization): JsonResponse
    {
        $organization->load(['locations', 'departments', 'designations']);

        return response()->json([
            'success' => true,
            'data' => $organization,
        ]);
    }

    public function update(Request $request, Organization $organization): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:128',
            'code' => 'sometimes|required|string|max:64|unique:organizations,code,'.$organization->id,
            'logo' => 'nullable|string|max:255',
            'address' => 'nullable|string',
            'timezone' => 'nullable|string|max:64',
            'settings' => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ]);

        $organization->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Organization updated successfully.',
            'data' => $organization,
        ]);
    }

    public function destroy(Organization $organization): JsonResponse
    {
        $organization->delete();

        return response()->json([
            'success' => true,
            'message' => 'Organization deleted successfully.',
        ]);
    }

    // =========================================================================
    // Locations Endpoints
    // =========================================================================

    public function listLocations(Request $request): JsonResponse
    {
        $query = Location::with('organization:id,name,code')
            ->select(['id', 'organization_id', 'name', 'code', 'address', 'timezone', 'coordinates', 'is_active', 'created_at', 'updated_at']);

        if ($request->has('organization_id')) {
            $query->where('organization_id', $request->organization_id);
        }

        $perPage = (int) $request->query('per_page', 50);

        return response()->json($query->orderBy('name')->paginate($perPage));
    }

    public function storeLocation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization_id' => 'nullable|exists:organizations,id',
            'name' => 'required|string|max:128',
            'code' => 'nullable|string|max:64',
            'address' => 'nullable|string',
            'timezone' => 'nullable|string|max:64',
            'coordinates' => 'nullable|string|max:64',
            'is_active' => 'nullable|boolean',
        ]);

        $orgId = $this->resolveOrganizationId($validated['organization_id'] ?? null);

        $location = Location::create([
            'organization_id' => $orgId,
            'name' => $validated['name'],
            'code' => $validated['code'] ?? null,
            'address' => $validated['address'] ?? null,
            'timezone' => $validated['timezone'] ?? null,
            'coordinates' => $validated['coordinates'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Location created successfully.',
            'data' => $location,
        ], 201);
    }

    public function showLocation(Location $location): JsonResponse
    {
        $location->load(['organization', 'devices']);

        return response()->json([
            'success' => true,
            'data' => $location,
        ]);
    }

    public function updateLocation(Request $request, Location $location): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:128',
            'code' => 'nullable|string|max:64',
            'address' => 'nullable|string',
            'timezone' => 'nullable|string|max:64',
            'coordinates' => 'nullable|string|max:64',
            'is_active' => 'nullable|boolean',
        ]);

        $location->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Location updated successfully.',
            'data' => $location,
        ]);
    }

    public function destroyLocation(Location $location): JsonResponse
    {
        if ($location->devices()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete location with attached devices. Please reassign devices first.',
            ], 422);
        }

        $location->delete();

        return response()->json([
            'success' => true,
            'message' => 'Location deleted successfully.',
        ]);
    }

    // =========================================================================
    // Departments Endpoints (with Tree & Cycle Guard)
    // =========================================================================

    public function listDepartments(Request $request): JsonResponse
    {
        $query = Department::with([
            'parent:id,name,code',
            'children:id,name,code,parent_id',
        ])->select(['id', 'organization_id', 'name', 'code', 'parent_id', 'head_id', 'description', 'is_active', 'created_at', 'updated_at']);

        if ($request->has('organization_id')) {
            $query->where('organization_id', $request->organization_id);
        }

        if ($request->has('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%");
            });
        }

        $perPage = (int) $request->query('per_page', 50);

        return response()->json($query->orderBy('name')->paginate($perPage));
    }

    public function departmentTree(Request $request): JsonResponse
    {
        $query = Department::whereNull('parent_id')
            ->with(['children.children.children']);

        if ($request->has('organization_id')) {
            $query->where('organization_id', $request->organization_id);
        }

        $tree = $query->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $tree,
        ]);
    }

    public function storeDepartment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization_id' => 'nullable|exists:organizations,id',
            'name' => 'required|string|max:128',
            'code' => 'nullable|string|max:64',
            'parent_id' => 'nullable|exists:departments,id',
            'head_id' => 'nullable|integer',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $orgId = $this->resolveOrganizationId($validated['organization_id'] ?? null);

        if (! empty($validated['parent_id'])) {
            $parent = Department::find($validated['parent_id']);
            if ($parent && (int) $parent->organization_id !== (int) $orgId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Parent department must belong to the same organization.',
                ], 422);
            }
        }

        $department = Department::create([
            'organization_id' => $orgId,
            'name' => $validated['name'],
            'code' => $validated['code'] ?? null,
            'parent_id' => $validated['parent_id'] ?? null,
            'head_id' => $validated['head_id'] ?? null,
            'description' => $validated['description'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Department created successfully.',
            'data' => $department,
        ], 201);
    }

    public function showDepartment(Department $department): JsonResponse
    {
        $department->load(['organization', 'parent', 'children']);

        return response()->json([
            'success' => true,
            'data' => $department,
        ]);
    }

    public function updateDepartment(Request $request, Department $department): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:128',
            'code' => 'nullable|string|max:64',
            'parent_id' => 'nullable|exists:departments,id',
            'head_id' => 'nullable|integer',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        // Circular Dependency & Organization Isolation Guard
        if (array_key_exists('parent_id', $validated) && $validated['parent_id']) {
            $parent = Department::find($validated['parent_id']);
            if ($parent && (int) $parent->organization_id !== (int) $department->organization_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Parent department must belong to the same organization.',
                ], 422);
            }

            if ($department->id == $validated['parent_id']) {
                return response()->json([
                    'success' => false,
                    'message' => 'A department cannot be its own parent.',
                ], 422);
            }

            if (in_array((int) $validated['parent_id'], $department->getDescendantIds(), true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Circular dependency detected: cannot set parent to one of its sub-departments.',
                ], 422);
            }
        }

        $department->update($validated);
        $department->load(['parent', 'children']);

        return response()->json([
            'success' => true,
            'message' => 'Department updated successfully.',
            'data' => $department,
        ]);
    }

    public function destroyDepartment(Department $department): JsonResponse
    {
        if ($department->children()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete department with active sub-departments. Reassign or delete children first.',
            ], 422);
        }

        $department->delete();

        return response()->json([
            'success' => true,
            'message' => 'Department deleted successfully.',
        ]);
    }

    // =========================================================================
    // Designations Endpoints
    // =========================================================================

    public function listDesignations(Request $request): JsonResponse
    {
        $query = Designation::with('organization:id,name,code')
            ->select(['id', 'organization_id', 'name', 'code', 'level', 'description', 'is_active', 'created_at', 'updated_at']);

        if ($request->has('organization_id')) {
            $query->where('organization_id', $request->organization_id);
        }

        $perPage = (int) $request->query('per_page', 50);

        return response()->json($query->orderBy('level')->orderBy('name')->paginate($perPage));
    }

    public function storeDesignation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization_id' => 'nullable|exists:organizations,id',
            'name' => 'required|string|max:128',
            'code' => 'nullable|string|max:64',
            'level' => 'nullable|integer|min:1|max:10',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $orgId = $this->resolveOrganizationId($validated['organization_id'] ?? null);

        $designation = Designation::create([
            'organization_id' => $orgId,
            'name' => $validated['name'],
            'code' => $validated['code'] ?? null,
            'level' => $validated['level'] ?? 1,
            'description' => $validated['description'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Designation created successfully.',
            'data' => $designation,
        ], 201);
    }

    public function showDesignation(Designation $designation): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $designation,
        ]);
    }

    public function updateDesignation(Request $request, Designation $designation): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:128',
            'code' => 'nullable|string|max:64',
            'level' => 'nullable|integer|min:1|max:10',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $designation->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Designation updated successfully.',
            'data' => $designation,
        ]);
    }

    public function destroyDesignation(Designation $designation): JsonResponse
    {
        $designation->delete();

        return response()->json([
            'success' => true,
            'message' => 'Designation deleted successfully.',
        ]);
    }
}
