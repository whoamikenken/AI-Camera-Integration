<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayrollExportController extends Controller
{
    /**
     * Standardized payroll export endpoint.
     */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $month = (int) $request->query('month', Carbon::now()->month);
        $year = (int) $request->query('year', Carbon::now()->year);
        $format = $request->query('format', 'json');

        $startDate = Carbon::create($year, $month, 1)->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();

        $aggregates = AttendanceRecord::query()
            ->selectRaw("
                employee_id,
                COUNT(CASE WHEN status IN ('present', 'late', 'early_out', 'late_and_early_out') THEN 1 END) as days_present,
                COUNT(CASE WHEN status = 'half_day' THEN 1 END) as days_half_day,
                COUNT(CASE WHEN status = 'on_leave' THEN 1 END) as days_on_leave,
                COUNT(CASE WHEN status = 'absent' THEN 1 END) as days_absent,
                SUM(total_work_hours) as total_work_hours,
                SUM(overtime_hours) as total_overtime_hours
            ")
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->groupBy('employee_id')
            ->get()
            ->keyBy('employee_id');

        if ($format === 'json') {
            $employees = Employee::with(['department', 'designation'])
                ->where('employment_status', 'active')
                ->orderBy('employee_code')
                ->get();

            $data = $employees->map(function ($emp) use ($aggregates, $startDate) {
                $agg = $aggregates->get($emp->id);
                $presentDays = (int) ($agg->days_present ?? 0);
                $halfDays = (int) ($agg->days_half_day ?? 0);
                $leaveDays = (int) ($agg->days_on_leave ?? 0);
                $absentDays = (int) ($agg->days_absent ?? 0);
                $totalHours = round((float) ($agg->total_work_hours ?? 0), 2);
                $overtimeHours = round((float) ($agg->total_overtime_hours ?? 0), 2);
                $payableDays = $presentDays + ($halfDays * 0.5) + $leaveDays;

                return [
                    'employee_id' => $emp->id,
                    'employee_code' => $emp->employee_code,
                    'employee_name' => $emp->name,
                    'department' => $emp->department?->name ?? 'General',
                    'designation' => $emp->designation?->name ?? 'Staff',
                    'month' => $startDate->format('Y-m'),
                    'present_days' => $presentDays,
                    'half_days' => $halfDays,
                    'leave_days' => $leaveDays,
                    'absent_days' => $absentDays,
                    'payable_days' => $payableDays,
                    'total_work_hours' => $totalHours,
                    'overtime_hours' => $overtimeHours,
                ];
            });

            return response()->json([
                'month' => $month,
                'year' => $year,
                'period' => $startDate->format('F Y'),
                'total_employees' => $data->count(),
                'data' => $data,
            ]);
        }

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"payroll_export_{$startDate->format('Y_m')}.csv\"",
        ];

        $callback = function () use ($startDate, $aggregates) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Employee Code',
                'Employee Name',
                'Department',
                'Designation',
                'Present Days',
                'Half Days',
                'Leave Days',
                'Absent Days',
                'Payable Days',
                'Total Work Hours',
                'Overtime Hours',
            ]);

            $employees = Employee::with(['department', 'designation'])
                ->where('employment_status', 'active')
                ->orderBy('employee_code');

            foreach ($employees->cursor() as $emp) {
                $agg = $aggregates->get($emp->id);
                $presentDays = (int) ($agg->days_present ?? 0);
                $halfDays = (int) ($agg->days_half_day ?? 0);
                $leaveDays = (int) ($agg->days_on_leave ?? 0);
                $absentDays = (int) ($agg->days_absent ?? 0);
                $totalHours = round((float) ($agg->total_work_hours ?? 0), 2);
                $overtimeHours = round((float) ($agg->total_overtime_hours ?? 0), 2);
                $payableDays = $presentDays + ($halfDays * 0.5) + $leaveDays;

                fputcsv($handle, \App\Support\CsvSanitizer::sanitizeRow([
                    $emp->employee_code,
                    $emp->name,
                    $emp->department?->name ?? 'General',
                    $emp->designation?->name ?? 'Staff',
                    $presentDays,
                    $halfDays,
                    $leaveDays,
                    $absentDays,
                    $payableDays,
                    $totalHours,
                    $overtimeHours,
                ]));
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }
}
