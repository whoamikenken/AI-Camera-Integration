const monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
const weekCounts = { 4: 0, 5: 0, 6: 0 };
const samples = {};

for (let y = 2020; y <= 2030; y++) {
    for (let m = 0; m < 12; m++) {
        const firstDayIndex = new Date(y, m, 1).getDay();
        const daysInMonth = new Date(y, m + 1, 0).getDate();
        const total = firstDayIndex + daysInMonth;
        const weeks = Math.ceil(total / 7);
        weekCounts[weeks] = (weekCounts[weeks] || 0) + 1;
        if (!samples[weeks]) {
            samples[weeks] = `${monthNames[m]} ${y} (firstDay=${firstDayIndex}, days=${daysInMonth})`;
        }
    }
}
console.log('Week counts distribution (2020-2030):', weekCounts);
console.log('Samples:', samples);
