<?php

namespace App\Http\Controllers;

use App\Jobs\SyncPersonnelJob;
use App\Models\Personnel;
use App\Services\ImageStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonnelController extends Controller
{
    public function __construct(protected ImageStorageService $storageService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Personnel::query()
            ->select([
                'id', 'customize_id', 'person_uuid', 'name', 'person_type',
                'gender', 'id_card', 'tel_num', 'address', 'native', 'notes',
                'mj_card_no', 'mj_card_from', 'birthday', 'photo_path',
                'temp_valid', 'valid_begin', 'valid_end', 'effect_number',
                'created_at', 'updated_at',
            ])
            ->with(['employee:id,personnel_id,employee_code,first_name,last_name,department_id,designation_id']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('id_card', 'ilike', "%{$search}%")
                  ->orWhere('tel_num', 'ilike', "%{$search}%");
                if (is_numeric($search)) {
                    $q->orWhere('customize_id', (int) $search);
                }
            });
        }

        if ($request->has('person_type') && $request->input('person_type') !== '') {
            $query->where('person_type', (int) $request->input('person_type'));
        }

        if ($request->has('temp_valid') && $request->input('temp_valid') !== '') {
            $query->where('temp_valid', (int) $request->input('temp_valid'));
        }

        $personnel = $query->orderBy('id', 'desc')->paginate($request->input('per_page', 15));

        return response()->json($personnel);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customize_id' => 'nullable|integer|unique:personnel,customize_id',
            'name' => 'required|string|max:64',
            'person_type' => 'required|integer|in:0,1',
            'gender' => 'nullable|integer|in:0,1',
            'id_card' => 'nullable|string|max:32',
            'tel_num' => 'nullable|string|max:32',
            'address' => 'nullable|string|max:128',
            'birthday' => 'nullable|date',
            'temp_valid' => 'nullable|integer|in:0,1',
            'valid_begin' => 'nullable|date',
            'valid_end' => 'nullable|date',
            'effect_number' => 'nullable|integer',
            'photo' => 'nullable|image|max:10240', // 10MB max upload
            'photo_base64' => 'nullable|string',
            'photo_url' => 'nullable|string',
            'photo_path' => 'nullable|string',
        ]);

        if ($request->hasFile('photo')) {
            $stored = $this->storageService->storeUploadedImage($request->file('photo'));
            $validated['photo_path'] = $stored['path'];
            $validated['photo_base64'] = $stored['base64'];
        } elseif (!empty($validated['photo_base64']) && str_starts_with($validated['photo_base64'], 'data:image')) {
            $stored = $this->storageService->storeFromBase64($validated['photo_base64'], 'personnel');
            if ($stored) {
                $validated['photo_path'] = $stored['path'];
                $validated['photo_base64'] = $stored['base64'];
            }
        } elseif (!empty($validated['photo_url']) || !empty($validated['photo_path'])) {
            $source = $validated['photo_url'] ?? $validated['photo_path'];
            if (!empty($validated['photo_url']) && filter_var($source, FILTER_VALIDATE_URL) && !$this->storageService->isSafeUrl($source)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'photo_url' => ['The provided photo URL points to a restricted or private network address.'],
                ]);
            }
            $stored = $this->storageService->storeFromUrlOrPath($source, 'personnel');
            if ($stored) {
                $validated['photo_path'] = $stored['path'];
                $validated['photo_base64'] = $stored['base64'];
            } else {
                $validated['photo_path'] = str_replace('/storage/', '', parse_url($source, PHP_URL_PATH) ?? $source);
            }
        }

        unset($validated['photo'], $validated['photo_url']);

        $person = Personnel::create($validated);

        return response()->json($person, 201);
    }

    public function show(Personnel $personnel): JsonResponse
    {
        return response()->json($personnel);
    }

    public function update(Request $request, Personnel $personnel): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:64',
            'person_type' => 'required|integer|in:0,1',
            'gender' => 'nullable|integer|in:0,1',
            'id_card' => 'nullable|string|max:32',
            'tel_num' => 'nullable|string|max:32',
            'address' => 'nullable|string|max:128',
            'birthday' => 'nullable|date',
            'temp_valid' => 'nullable|integer|in:0,1',
            'valid_begin' => 'nullable|date',
            'valid_end' => 'nullable|date',
            'effect_number' => 'nullable|integer',
            'photo' => 'nullable|image|max:10240',
            'photo_base64' => 'nullable|string',
            'photo_url' => 'nullable|string',
            'photo_path' => 'nullable|string',
        ]);

        if ($request->hasFile('photo')) {
            $stored = $this->storageService->storeUploadedImage($request->file('photo'));
            $validated['photo_path'] = $stored['path'];
            $validated['photo_base64'] = $stored['base64'];
        } elseif (!empty($validated['photo_base64']) && str_starts_with($validated['photo_base64'], 'data:image') && $validated['photo_base64'] !== $personnel->photo_base64) {
            $stored = $this->storageService->storeFromBase64($validated['photo_base64'], 'personnel');
            if ($stored) {
                $validated['photo_path'] = $stored['path'];
                $validated['photo_base64'] = $stored['base64'];
            }
        } elseif ((!empty($validated['photo_url']) || !empty($validated['photo_path'])) && ($validated['photo_url'] ?? $validated['photo_path']) !== $personnel->photo_path) {
            $source = $validated['photo_url'] ?? $validated['photo_path'];
            if (!empty($validated['photo_url']) && filter_var($source, FILTER_VALIDATE_URL) && !$this->storageService->isSafeUrl($source)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'photo_url' => ['The provided photo URL points to a restricted or private network address.'],
                ]);
            }
            $stored = $this->storageService->storeFromUrlOrPath($source, 'personnel');
            if ($stored) {
                $validated['photo_path'] = $stored['path'];
                $validated['photo_base64'] = $stored['base64'];
            } else {
                $validated['photo_path'] = str_replace('/storage/', '', parse_url($source, PHP_URL_PATH) ?? $source);
            }
        }

        unset($validated['photo'], $validated['photo_url']);

        $personnel->update($validated);

        return response()->json($personnel);
    }

    public function destroy(Personnel $personnel): JsonResponse
    {
        $personnel->delete();

        return response()->json(['message' => 'Personnel removed successfully']);
    }

    public function syncNow(Request $request, Personnel $personnel): JsonResponse
    {
        $targetDeviceId = $request->input('device_id');
        SyncPersonnelJob::dispatch($personnel->id, 'ADD', $targetDeviceId);

        return response()->json(['message' => 'Sync task dispatched successfully']);
    }

    public function convertToEmployee(Personnel $personnel): JsonResponse
    {
        $personnel->load('employee');
        if ($personnel->employee) {
            return response()->json([
                'message' => "Personnel {$personnel->name} is already registered in the Employee Directory.",
                'employee' => $personnel->employee,
            ], 200);
        }

        $employeeCode = (string) $personnel->customize_id;

        // Ensure unique employee_code in employees table
        if (\App\Models\Employee::where('employee_code', $employeeCode)->exists()) {
            $employeeCode = 'EMP-' . $personnel->customize_id;
        }

        $nameParts = explode(' ', trim($personnel->name), 2);
        $firstName = $nameParts[0] ?: "Person {$personnel->customize_id}";
        $lastName = $nameParts[1] ?? '';

        $employee = \App\Models\Employee::create([
            'personnel_id' => $personnel->id,
            'employee_code' => $employeeCode,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'employment_type' => 'full-time',
            'employment_status' => 'active',
            'phone' => $personnel->tel_num,
            'avatar' => $personnel->photo_path,
            'date_of_joining' => now()->toDateString(),
        ]);

        return response()->json([
            'message' => "Personnel {$personnel->name} (#{$personnel->customize_id}) added to Employee Directory as Employee #{$employee->employee_code}.",
            'employee' => $employee,
        ], 201);
    }
}
