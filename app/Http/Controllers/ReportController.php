<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Visit;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /**
     * Daily attendance metrics report.
     */
    public function dailyAttendance(Request $request): JsonResponse
    {
        $date = $request->query('date', Carbon::today()->toDateString());

        $records = AttendanceRecord::with(['employee.department', 'employee.designation'])
            ->whereDate('date', $date)
            ->get();

        $departments = Department::withCount(['employees' => fn($q) => $q->where('employment_status', 'active')])->get();

        $summary = [
            'date' => $date,
            'total_employees' => Employee::where('employment_status', 'active')->count(),
            'present' => $records->whereIn('status', ['present', 'late', 'early_out', 'late_and_early_out'])->count(),
            'late' => $records->whereIn('status', ['late', 'late_and_early_out'])->count(),
            'early_out' => $records->whereIn('status', ['early_out', 'late_and_early_out'])->count(),
            'absent' => $records->where('status', 'absent')->count(),
            'half_day' => $records->where('status', 'half_day')->count(),
            'on_leave' => $records->where('status', 'on_leave')->count(),
            'holiday' => $records->where('status', 'holiday')->count(),
        ];

        return response()->json([
            'summary' => $summary,
            'departments' => $departments,
            'records' => $records,
        ]);
    }

    /**
     * Monthly attendance aggregation per employee.
     */
    public function monthlyAttendance(Request $request): JsonResponse
    {
        $month = (int) $request->query('month', Carbon::now()->month);
        $year = (int) $request->query('year', Carbon::now()->year);

        $employees = Employee::with('department')
            ->where('employment_status', 'active')
            ->get();

        $startDate = Carbon::create($year, $month, 1)->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();

        $aggregates = AttendanceRecord::query()
            ->selectRaw("
                employee_id,
                COUNT(CASE WHEN status IN ('present', 'late', 'early_out', 'late_and_early_out') THEN 1 END) as days_present,
                COUNT(CASE WHEN status IN ('late', 'late_and_early_out') THEN 1 END) as days_late,
                COUNT(CASE WHEN status = 'absent' THEN 1 END) as days_absent,
                COUNT(CASE WHEN status = 'on_leave' THEN 1 END) as days_on_leave,
                SUM(total_work_hours) as total_work_hours,
                SUM(overtime_hours) as total_overtime_hours
            ")
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->groupBy('employee_id')
            ->get()
            ->keyBy('employee_id');

        $data = $employees->map(function ($emp) use ($aggregates) {
            $agg = $aggregates->get($emp->id);

            return [
                'employee_id' => $emp->id,
                'employee_code' => $emp->employee_code,
                'employee_name' => $emp->name,
                'department' => $emp->department?->name ?? 'N/A',
                'days_present' => (int) ($agg->days_present ?? 0),
                'days_late' => (int) ($agg->days_late ?? 0),
                'days_absent' => (int) ($agg->days_absent ?? 0),
                'days_on_leave' => (int) ($agg->days_on_leave ?? 0),
                'total_work_hours' => round((float) ($agg->total_work_hours ?? 0), 2),
                'total_overtime_hours' => round((float) ($agg->total_overtime_hours ?? 0), 2),
            ];
        });

        return response()->json([
            'month' => $month,
            'year' => $year,
            'data' => $data,
        ]);
    }

    /**
     * Generalized export engine (CSV / PDF / JSON).
     */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $type = $request->query('type', 'attendance');
        $format = $request->query('format', 'csv');

        if ($type === 'attendance') {
            if ($format === 'json') {
                $records = AttendanceRecord::with(['employee.department'])
                    ->orderBy('date', 'desc')
                    ->limit(500)
                    ->get();
                return response()->json(['data' => $records]);
            }

            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="attendance_report_' . date('Y-m-d') . '.csv"',
            ];

            $callback = function () {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, ['Date', 'Employee Code', 'Employee Name', 'Department', 'Clock In', 'Clock Out', 'Status', 'Work Hours', 'Overtime']);
                $records = AttendanceRecord::with(['employee.department'])
                    ->orderBy('date', 'desc')
                    ->limit(500);

                foreach ($records->cursor() as $r) {
                    $dateVal = $r->date instanceof \DateTimeInterface ? $r->date->format('Y-m-d') : $r->date;
                    $firstClockIn = $r->first_clock_in instanceof \DateTimeInterface ? $r->first_clock_in->format('H:i:s') : $r->first_clock_in;
                    $lastClockOut = $r->last_clock_out instanceof \DateTimeInterface ? $r->last_clock_out->format('H:i:s') : $r->last_clock_out;

                    fputcsv($handle, \App\Support\CsvSanitizer::sanitizeRow([
                        $dateVal,
                        $r->employee?->employee_code,
                        $r->employee?->name,
                        $r->employee?->department?->name,
                        $firstClockIn,
                        $lastClockOut,
                        $r->status,
                        $r->total_work_hours,
                        $r->overtime_hours,
                    ]));
                }
                fclose($handle);
            };

            return response()->stream($callback, 200, $headers);
        }

        if ($type === 'visitors') {
            if ($format === 'json') {
                $visits = Visit::with(['visitor', 'host'])->orderBy('created_at', 'desc')->limit(500)->get();
                return response()->json(['data' => $visits]);
            }

            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="visitors_report_' . date('Y-m-d') . '.csv"',
            ];

            $callback = function () {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, ['Date', 'Visitor Name', 'Company', 'Host Employee', 'Purpose', 'Check In', 'Check Out', 'Badge', 'Status']);
                $visits = Visit::with(['visitor', 'host'])->orderBy('created_at', 'desc')->limit(500);

                foreach ($visits->cursor() as $v) {
                    fputcsv($handle, \App\Support\CsvSanitizer::sanitizeRow([
                        $v->created_at?->format('Y-m-d'),
                        $v->visitor?->name,
                        $v->visitor?->company,
                        $v->host?->name,
                        $v->purpose,
                        $v->check_in_time?->format('H:i:s'),
                        $v->check_out_time?->format('H:i:s'),
                        $v->badge_number,
                        $v->status,
                    ]));
                }
                fclose($handle);
            };

            return response()->stream($callback, 200, $headers);
        }

        return response()->json(['message' => 'Report exported.']);
    }
}
