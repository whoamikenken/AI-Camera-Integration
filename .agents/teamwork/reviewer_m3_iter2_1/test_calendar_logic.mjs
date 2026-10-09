import { ref, computed } from 'vue';

function createCalendarLogic(mockRecords = []) {
    const currentDate = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1));
    const monthRecords = ref(mockRecords);
    const loading = ref(false);

    const currentYear = computed(() => currentDate.value.getFullYear());
    const currentMonth = computed(() => currentDate.value.getMonth() + 1);
    const currentMonthName = computed(() => currentDate.value.toLocaleString('default', { month: 'long' }));

    const stats = computed(() => {
        return {
            present: monthRecords.value.filter(r => ['present', 'late', 'early_out', 'late_and_early_out', 'half_day'].includes(r.status)).length,
            absent: monthRecords.value.filter(r => r.status === 'absent').length,
            late: monthRecords.value.filter(r => r.is_late || ['late', 'late_and_early_out'].includes(r.status)).length,
            leave: monthRecords.value.filter(r => r.status === 'on_leave').length,
        };
    });

    const prevMonth = () => {
        if (loading.value) return;
        currentDate.value = new Date(currentDate.value.getFullYear(), currentDate.value.getMonth() - 1, 1);
    };

    const nextMonth = () => {
        if (loading.value) return;
        currentDate.value = new Date(currentDate.value.getFullYear(), currentDate.value.getMonth() + 1, 1);
    };

    const setDate = (y, m) => {
        currentDate.value = new Date(y, m - 1, 1);
    };

    const calendarDays = computed(() => {
        const year = currentYear.value;
        const month = currentMonth.value - 1;
        const firstDayIndex = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const days = [];

        for (let i = 0; i < firstDayIndex; i++) {
            days.push({ isCurrentMonth: false, dayNum: '' });
        }

        for (let d = 1; d <= daysInMonth; d++) {
            const dStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
            const record = monthRecords.value.find(r => r.date?.startsWith(dStr) || r.date === dStr);
            const dateObj = new Date(year, month, d);
            const formattedDate = dateObj.toLocaleDateString('default', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric',
            });

            days.push({
                isCurrentMonth: true,
                dayNum: d,
                dateStr: dStr,
                formattedDate,
                status: record?.status || '',
                firstIn: record?.first_clock_in ? record.first_clock_in.substring(11, 16) : null,
                lastOut: record?.last_clock_out ? record.last_clock_out.substring(11, 16) : null,
                totalWorkHours: record?.total_work_hours ? Number(record.total_work_hours).toFixed(1) : null,
                remarks: record?.remarks || null,
            });
        }

        const remainder = days.length % 7;
        if (remainder > 0) {
            for (let i = 0; i < 7 - remainder; i++) {
                days.push({ isCurrentMonth: false, dayNum: '' });
            }
        }

        return days;
    });

    const calendarWeeks = computed(() => {
        const days = calendarDays.value;
        const weeks = [];
        for (let i = 0; i < days.length; i += 7) {
            weeks.push(days.slice(i, i + 7));
        }
        return weeks;
    });

    const formatStatus = (status) => {
        switch (status) {
            case 'present': return 'Present';
            case 'late': return 'Late arrival';
            case 'late_and_early_out': return 'Late arrival and early departure';
            case 'early_out': return 'Early departure';
            case 'half_day': return 'Half day';
            case 'absent': return 'Absent';
            case 'on_leave': return 'On leave';
            case 'holiday': return 'Holiday';
            default: return status || 'Unknown';
        }
    };

    const getDayAriaLabel = (day) => {
        if (!day.isCurrentMonth) {
            return 'Empty';
        }

        const parts = [day.formattedDate];

        if (day.status) {
            parts.push(`Status: ${formatStatus(day.status)}`);
        } else {
            parts.push('No attendance recorded');
        }

        if (day.firstIn) {
            parts.push(`Clock in: ${day.firstIn}`);
        }

        if (day.lastOut) {
            parts.push(`Clock out: ${day.lastOut}`);
        }

        if (day.totalWorkHours && day.totalWorkHours > 0) {
            parts.push(`Total hours: ${day.totalWorkHours}h`);
        }

        if (day.remarks) {
            parts.push(`Notes: ${day.remarks}`);
        }

        return parts.join(', ');
    };

    return {
        currentDate,
        monthRecords,
        loading,
        currentYear,
        currentMonth,
        currentMonthName,
        stats,
        prevMonth,
        nextMonth,
        setDate,
        calendarDays,
        calendarWeeks,
        getDayAriaLabel,
    };
}

// TEST SUITE
console.log('=== Running Calendar Logic Unit Tests ===');

// 1. Initial date day is 1
const cal = createCalendarLogic();
if (cal.currentDate.value.getDate() !== 1) {
    throw new Error('Initial date day is not 1!');
}
console.log('Test 1 Passed: Initial currentDate day is normalized to 1.');

// 2. Test 4-row month (Feb 2026: starts Sunday, 28 days)
cal.setDate(2026, 2);
if (cal.calendarWeeks.value.length !== 4) {
    throw new Error(`Expected 4 weeks for Feb 2026, got ${cal.calendarWeeks.value.length}`);
}
console.log('Test 2 Passed: Feb 2026 correctly evaluates to 4 weeks (skeleton rows = 4).');

// 3. Test 5-row month (Jan 2026: starts Thursday, 31 days)
cal.setDate(2026, 1);
if (cal.calendarWeeks.value.length !== 5) {
    throw new Error(`Expected 5 weeks for Jan 2026, got ${cal.calendarWeeks.value.length}`);
}
console.log('Test 3 Passed: Jan 2026 correctly evaluates to 5 weeks (skeleton rows = 5).');

// 4. Test 6-row month (May 2026: starts Friday, 31 days)
cal.setDate(2026, 5);
if (cal.calendarWeeks.value.length !== 6) {
    throw new Error(`Expected 6 weeks for May 2026, got ${cal.calendarWeeks.value.length}`);
}
console.log('Test 4 Passed: May 2026 correctly evaluates to 6 weeks (skeleton rows = 6).');

// 5. Test another 6-row month (Aug 2026: starts Saturday, 31 days)
cal.setDate(2026, 8);
if (cal.calendarWeeks.value.length !== 6) {
    throw new Error(`Expected 6 weeks for Aug 2026, got ${cal.calendarWeeks.value.length}`);
}
console.log('Test 5 Passed: Aug 2026 correctly evaluates to 6 weeks (skeleton rows = 6).');

// 6. Test Leap Year Feb (Feb 2024: 29 days, starts Thursday -> 5 weeks)
cal.setDate(2024, 2);
if (cal.calendarWeeks.value.length !== 5) {
    throw new Error(`Expected 5 weeks for Feb 2024 (leap year), got ${cal.calendarWeeks.value.length}`);
}
console.log('Test 6 Passed: Feb 2024 (leap) evaluates to 5 weeks.');

// 7. Test Year rollover forward (Dec 2026 -> Jan 2027)
cal.setDate(2026, 12);
cal.nextMonth();
if (cal.currentYear.value !== 2027 || cal.currentMonth.value !== 1 || cal.currentDate.value.getDate() !== 1) {
    throw new Error(`Failed year rollover forward: ${cal.currentYear.value}-${cal.currentMonth.value}`);
}
console.log('Test 7 Passed: Dec 2026 -> Jan 2027 forward navigation.');

// 8. Test Year rollover backward (Jan 2026 -> Dec 2025)
cal.setDate(2026, 1);
cal.prevMonth();
if (cal.currentYear.value !== 2025 || cal.currentMonth.value !== 12 || cal.currentDate.value.getDate() !== 1) {
    throw new Error(`Failed year rollover backward: ${cal.currentYear.value}-${cal.currentMonth.value}`);
}
console.log('Test 8 Passed: Jan 2026 -> Dec 2025 backward navigation.');

// 9. Test Loading Guard in prevMonth and nextMonth
cal.setDate(2026, 6);
cal.loading.value = true;
cal.prevMonth();
if (cal.currentMonth.value !== 6) {
    throw new Error('prevMonth should be ignored while loading=true');
}
cal.nextMonth();
if (cal.currentMonth.value !== 6) {
    throw new Error('nextMonth should be ignored while loading=true');
}
cal.loading.value = false;
console.log('Test 9 Passed: prevMonth and nextMonth respect loading guard.');

// 10. Test Stats calculation and Day Aria Label
const sampleRecords = [
    { date: '2026-06-01', status: 'present', first_clock_in: '2026-06-01 08:55:00', last_clock_out: '2026-06-01 17:05:00', total_work_hours: 8.2, remarks: 'On time' },
    { date: '2026-06-02', status: 'late', is_late: true, first_clock_in: '2026-06-02 09:20:00', last_clock_out: '2026-06-02 17:00:00', total_work_hours: 7.7 },
    { date: '2026-06-03', status: 'absent' },
    { date: '2026-06-04', status: 'on_leave' },
    { date: '2026-06-05', status: 'half_day', total_work_hours: 4.0 },
];
const calWithRecords = createCalendarLogic(sampleRecords);
calWithRecords.setDate(2026, 6);

if (calWithRecords.stats.value.present !== 3) { // present, late, half_day count as present
    throw new Error(`Expected present=3, got ${calWithRecords.stats.value.present}`);
}
if (calWithRecords.stats.value.late !== 1) {
    throw new Error(`Expected late=1, got ${calWithRecords.stats.value.late}`);
}
if (calWithRecords.stats.value.absent !== 1) {
    throw new Error(`Expected absent=1, got ${calWithRecords.stats.value.absent}`);
}
if (calWithRecords.stats.value.leave !== 1) {
    throw new Error(`Expected leave=1, got ${calWithRecords.stats.value.leave}`);
}
console.log('Test 10 Passed: Stats computation accurate.');

// 11. Test getDayAriaLabel for active day vs empty day
const days = calWithRecords.calendarDays.value;
const emptyDay = days.find(d => !d.isCurrentMonth);
if (emptyDay && calWithRecords.getDayAriaLabel(emptyDay) !== 'Empty') {
    throw new Error(`Expected empty day aria label to be 'Empty', got ${calWithRecords.getDayAriaLabel(emptyDay)}`);
}
const recordedDay = days.find(d => d.dateStr === '2026-06-01');
const ariaLabel = calWithRecords.getDayAriaLabel(recordedDay);
if (!ariaLabel.includes('Status: Present') || !ariaLabel.includes('Clock in: 08:55') || !ariaLabel.includes('Clock out: 17:05') || !ariaLabel.includes('Total hours: 8.2h') || !ariaLabel.includes('Notes: On time')) {
    throw new Error(`Aria label missing expected segments: ${ariaLabel}`);
}
console.log('Test 11 Passed: getDayAriaLabel generates comprehensive accessibility string.');

console.log('=== All 11 Unit Tests PASSED Successfully ===');
