<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\Personnel;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Employee::with([
            'personnel:id,customize_id,person_uuid,name,person_type,tel_num,photo_path',
            'department:id,name',
            'designation:id,name',
            'location:id,name',
            'shift:id,name,shift_start,shift_end',
            'manager:id,first_name,last_name',
            'reportingManager:id,first_name,last_name',
        ]);

        if ($request->filled('status')) {
            $query->where('employment_status', $request->query('status'));
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->query('department_id'));
        }

        if ($request->filled('designation_id')) {
            $query->where('designation_id', $request->query('designation_id'));
        }

        if ($request->filled('location_id')) {
            $query->where('location_id', $request->query('location_id'));
        }

        if ($request->filled('shift_id')) {
            $query->where('shift_id', $request->query('shift_id'));
        }

        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->query('organization_id'));
        }

        if ($request->filled('employment_type')) {
            $query->where('employment_type', $request->query('employment_type'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('employee_code', 'like', "%{$search}%")
                  ->orWhere('work_email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $perPage = (int) $request->query('per_page', 15);
        $employees = $query->orderBy('first_name')->paginate($perPage);

        return response()->json($employees);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'personnel_id' => 'nullable|exists:personnel,id',
            'user_id' => 'nullable|exists:users,id',
            'organization_id' => 'nullable|exists:organizations,id',
            'department_id' => 'nullable|exists:departments,id',
            'designation_id' => 'nullable|exists:designations,id',
            'location_id' => 'nullable|exists:locations,id',
            'reporting_manager_id' => 'nullable|exists:employees,id',
            'shift_id' => 'nullable|exists:shifts,id',
            'employee_code' => 'required|string|max:64|unique:employees,employee_code',
            'first_name' => 'required|string|max:64',
            'last_name' => 'nullable|string|max:64',
            'employment_type' => 'nullable|string',
            'employment_status' => 'nullable|string',
            'date_of_joining' => 'nullable|date',
            'date_of_leaving' => 'nullable|date',
            'work_email' => 'nullable|email|max:128|unique:employees,work_email',
            'personal_email' => 'nullable|email|max:128',
            'phone' => 'nullable|string|max:32',
            'avatar' => 'nullable|string|max:255',
            'photo_base64' => 'nullable|string',
            'photo_path' => 'nullable|string|max:255',
            'emergency_contact_name' => 'nullable|string|max:128',
            'emergency_contact_phone' => 'nullable|string|max:32',
        ]);

        if (empty($validated['employment_status'])) {
            $validated['employment_status'] = 'active';
        }

        DB::beginTransaction();
        try {
            // 1-to-1 Biometric Bridge: Auto-provision Personnel entity if not provided
            if (empty($validated['personnel_id'])) {
                $personnel = Personnel::create([
                    'name' => trim("{$validated['first_name']} " . ($validated['last_name'] ?? '')),
                    'person_type' => in_array($validated['employment_status'], ['suspended', 'terminated', 'resigned']) ? 1 : 0,
                    'tel_num' => $validated['phone'] ?? null,
                    'photo_base64' => $validated['photo_base64'] ?? null,
                    'photo_path' => $validated['photo_path'] ?? $validated['avatar'] ?? null,
                    'notes' => "Employee: {$validated['employee_code']}",
                ]);
                $validated['personnel_id'] = $personnel->id;
            }

            unset($validated['photo_base64'], $validated['photo_path'], $validated['avatar']);

            $employee = Employee::create($validated);
            $employee->load(['personnel', 'department', 'designation', 'location', 'shift', 'manager', 'reportingManager']);

            DB::commit();

            return response()->json([
                'message' => 'Employee created successfully.',
                'data' => $employee,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function show(int $id): JsonResponse
    {
        $employee = Employee::with([
            'personnel',
            'department',
            'designation',
            'location',
            'shift',
            'manager',
            'reportingManager',
            'directReports',
            'user',
            'shiftAssignments.shift',
        ])->findOrFail($id);

        return response()->json(['data' => $employee]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $employee = Employee::findOrFail($id);

        $validated = $request->validate([
            'personnel_id' => 'nullable|exists:personnel,id',
            'user_id' => 'nullable|exists:users,id',
            'organization_id' => 'nullable|exists:organizations,id',
            'department_id' => 'nullable|exists:departments,id',
            'designation_id' => 'nullable|exists:designations,id',
            'location_id' => 'nullable|exists:locations,id',
            'reporting_manager_id' => 'nullable|exists:employees,id',
            'shift_id' => 'nullable|exists:shifts,id',
            'employee_code' => 'sometimes|required|string|max:64|unique:employees,employee_code,' . $employee->id,
            'first_name' => 'sometimes|required|string|max:64',
            'last_name' => 'nullable|string|max:64',
            'employment_type' => 'nullable|string',
            'employment_status' => 'nullable|string',
            'date_of_joining' => 'nullable|date',
            'date_of_leaving' => 'nullable|date',
            'work_email' => 'nullable|email|max:128|unique:employees,work_email,' . $employee->id,
            'personal_email' => 'nullable|email|max:128',
            'phone' => 'nullable|string|max:32',
            'avatar' => 'nullable|string|max:255',
            'photo_base64' => 'nullable|string',
            'photo_path' => 'nullable|string|max:255',
            'emergency_contact_name' => 'nullable|string|max:128',
            'emergency_contact_phone' => 'nullable|string|max:32',
        ]);

        DB::beginTransaction();
        try {
            // Update biometric personnel record if linked
            if ($employee->personnel) {
                $personnelUpdates = [];
                if (isset($validated['first_name']) || isset($validated['last_name'])) {
                    $fn = $validated['first_name'] ?? $employee->first_name;
                    $ln = $validated['last_name'] ?? $employee->last_name;
                    $personnelUpdates['name'] = trim("{$fn} {$ln}");
                }
                if (isset($validated['phone'])) {
                    $personnelUpdates['tel_num'] = $validated['phone'];
                }
                if (!empty($validated['photo_base64'])) {
                    $personnelUpdates['photo_base64'] = $validated['photo_base64'];
                }
                if (!empty($validated['photo_path']) || !empty($validated['avatar'])) {
                    $personnelUpdates['photo_path'] = $validated['photo_path'] ?? $validated['avatar'];
                }
                if (isset($validated['employment_status'])) {
                    $personnelUpdates['person_type'] = in_array($validated['employment_status'], ['suspended', 'terminated', 'resigned']) ? 1 : 0;
                }

                if (!empty($personnelUpdates)) {
                    $employee->personnel->update($personnelUpdates);
                }
            }

            unset($validated['photo_base64'], $validated['photo_path']);

            $employee->update($validated);
            $employee->load(['personnel', 'department', 'designation', 'location', 'shift', 'manager', 'reportingManager']);

            DB::commit();

            return response()->json([
                'message' => 'Employee updated successfully.',
                'data' => $employee,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function destroy(int $id): JsonResponse
    {
        $employee = Employee::findOrFail($id);

        DB::beginTransaction();
        try {
            $employee->delete(); // Triggers EmployeeObserver::deleting to cascade delete personnel, telemetry access logs, punches, and dispatch MQTT edge de-provisioning

            DB::commit();

            return response()->json([
                'message' => 'Employee, personnel, and telemetry data deleted successfully.',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function attendanceSummary(Request $request, int $id): JsonResponse
    {
        $employee = Employee::with('personnel')->findOrFail($id);

        $from = $request->query('from')
            ? Carbon::parse($request->query('from'))->startOfDay()
            : ($request->query('month') ? Carbon::parse($request->query('month') . '-01')->startOfMonth() : Carbon::now()->startOfMonth());

        $to = $request->query('to')
            ? Carbon::parse($request->query('to'))->endOfDay()
            : ($request->query('month') ? Carbon::parse($request->query('month') . '-01')->endOfMonth() : Carbon::now()->endOfMonth());

        $totalWorkingDays = 0;
        $current = $from->copy();
        while ($current->lte($to)) {
            if (!$employee->isRestDay($current) && !$employee->isHoliday($current)) {
                $totalWorkingDays++;
            }
            $current->addDay();
        }

        $presentDays = 0;
        $absentDays = 0;
        $lateDays = 0;
        $halfDays = 0;
        $onLeaveDays = 0;
        $totalWorkHours = 0.0;
        $totalOvertimeHours = 0.0;
        $recentPunches = [];

        // Progressive integration: query attendance_records if table exists (M3+)
        if (Schema::hasTable('attendance_records')) {
            $records = DB::table('attendance_records')
                ->where('employee_id', $employee->id)
                ->whereBetween('date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
                ->get();

            foreach ($records as $record) {
                if (in_array($record->status, ['present', 'late', 'early_out', 'late_and_early_out'])) {
                    $presentDays++;
                } elseif ($record->status === 'absent') {
                    $absentDays++;
                } elseif ($record->status === 'half_day') {
                    $halfDays++;
                } elseif ($record->status === 'on_leave') {
                    $onLeaveDays++;
                }

                if (!empty($record->is_late)) {
                    $lateDays++;
                }

                $totalWorkHours += (float) ($record->total_work_hours ?? 0);
                $totalOvertimeHours += (float) ($record->overtime_hours ?? 0);
            }
        }

        // Query raw punches or access logs if available
        if (Schema::hasTable('attendance_punches')) {
            $recentPunches = DB::table('attendance_punches')
                ->where('employee_id', $employee->id)
                ->whereBetween('punch_time', [$from, $to])
                ->orderBy('punch_time', 'desc')
                ->limit(10)
                ->get();
        } elseif ($employee->personnel_id && Schema::hasTable('access_logs')) {
            $cId = $employee->personnel?->customize_id;
            if ($cId) {
                $recentPunches = DB::table('access_logs')
                    ->where('customize_id', $cId)
                    ->whereBetween('captured_at', [$from, $to])
                    ->orderBy('captured_at', 'desc')
                    ->limit(10)
                    ->get();
            }
        }

        return response()->json([
            'employee_id' => $employee->id,
            'period' => [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
            ],
            'total_working_days' => $totalWorkingDays,
            'present_days' => $presentDays,
            'absent_days' => $absentDays,
            'late_days' => $lateDays,
            'half_days' => $halfDays,
            'on_leave_days' => $onLeaveDays,
            'total_work_hours' => round($totalWorkHours, 2),
            'total_overtime_hours' => round($totalOvertimeHours, 2),
            'recent_punches' => $recentPunches,
        ]);
    }

    public function assignShift(Request $request, int $id): JsonResponse
    {
        $employee = Employee::findOrFail($id);

        $validated = $request->validate([
            'shift_id' => 'required|exists:shifts,id',
            'effective_from' => 'required|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'assigned_days' => 'nullable|array',
        ]);

        $effFrom = $validated['effective_from'];
        $prevEndDate = Carbon::parse($effFrom)->subDay()->toDateString();

        DB::transaction(function () use ($employee, $validated, $prevEndDate, $request, &$assignment) {
            // Intelligent shift rotation capping: cap previous open-ended assignments
            EmployeeShiftAssignment::where('employee_id', $employee->id)
                ->where('effective_from', '<=', $validated['effective_from'])
                ->whereNull('effective_to')
                ->update(['effective_to' => $prevEndDate]);

            $assignment = EmployeeShiftAssignment::create([
                'employee_id' => $employee->id,
                'shift_id' => $validated['shift_id'],
                'effective_from' => $validated['effective_from'],
                'effective_to' => $validated['effective_to'] ?? null,
                'assigned_days' => $validated['assigned_days'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            $employee->update(['shift_id' => $validated['shift_id']]);
        });

        return response()->json([
            'message' => 'Shift assigned successfully.',
            'data' => $assignment->load('shift'),
        ], 201);
    }

    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $format = $request->query('format', 'csv');

        $query = Employee::with(['department', 'designation', 'location', 'shift'])
            ->orderBy('employee_code');

        if ($format === 'json') {
            $headers = [
                'Content-Type' => 'application/json',
                'Content-Disposition' => 'attachment; filename="employees_export_' . date('Y-m-d') . '.json"',
            ];

            $callback = function () use ($query) {
                $handle = fopen('php://output', 'w');
                fwrite($handle, '{"data":[');
                
                $first = true;
                foreach ($query->cursor() as $emp) {
                    if (!$first) {
                        fwrite($handle, ',');
                    }
                    fwrite($handle, json_encode($emp));
                    $first = false;
                }
                
                fwrite($handle, ']}');
                fclose($handle);
            };

            return response()->stream($callback, 200, $headers);
        }

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="employees_export_' . date('Y-m-d') . '.csv"',
        ];

        $callback = function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Employee Code',
                'First Name',
                'Last Name',
                'Work Email',
                'Phone',
                'Department',
                'Designation',
                'Location',
                'Shift',
                'Status',
                'Date of Joining',
            ]);

            foreach ($query->cursor() as $emp) {
                fputcsv($handle, \App\Support\CsvSanitizer::sanitizeRow([
                    $emp->employee_code,
                    $emp->first_name,
                    $emp->last_name,
                    $emp->work_email,
                    $emp->phone,
                    $emp->department?->name,
                    $emp->designation?->name,
                    $emp->location?->name,
                    $emp->shift?->name,
                    $emp->employment_status,
                    $emp->date_of_joining?->format('Y-m-d'),
                ]));
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt',
        ]);

        $file = $request->file('file');
        $handle = fopen($file->getRealPath(), 'r');
        $header = fgetcsv($handle);

        $imported = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            $rowNum = 1;
            while (($row = fgetcsv($handle)) !== false) {
                $rowNum++;
                if (empty($row[0])) {
                    continue;
                }

                $data = array_combine($header, $row);
                $employeeCode = trim($data['Employee Code'] ?? $data['employee_code'] ?? '');
                $firstName = trim($data['First Name'] ?? $data['first_name'] ?? '');
                $lastName = trim($data['Last Name'] ?? $data['last_name'] ?? '');
                $email = trim($data['Work Email'] ?? $data['work_email'] ?? $data['email'] ?? '');
                $phone = trim($data['Phone'] ?? $data['phone'] ?? '');

                if (!$employeeCode || !$firstName) {
                    $errors[] = "Row {$rowNum}: Missing employee_code or first_name";
                    continue;
                }

                Employee::updateOrCreate(
                    ['employee_code' => $employeeCode],
                    [
                        'first_name' => $firstName,
                        'last_name' => $lastName ?: null,
                        'work_email' => $email ?: null,
                        'phone' => $phone ?: null,
                        'employment_status' => 'active',
                    ]
                );
                $imported++;
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Import failed: ' . $e->getMessage()], 422);
        } finally {
            fclose($handle);
        }

        return response()->json([
            'message' => "Successfully imported {$imported} employees.",
            'imported_count' => $imported,
            'errors' => $errors,
        ]);
    }
}
