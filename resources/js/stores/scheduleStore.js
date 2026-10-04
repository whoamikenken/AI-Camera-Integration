import { defineStore } from 'pinia';
import apiClient from '../api/client';
import notify from '../utils/notify';

export const useScheduleStore = defineStore('schedule', {
    state: () => ({
        shifts: [],
        holidays: [],
        shiftAssignments: [],
        loading: false,
        saving: false,
        calendarMonth: new Date().getMonth(), // 0-11
        calendarYear: new Date().getFullYear(),
        calendarViewMode: 'month', // 'month' | 'list'
    }),

    getters: {
        activeShifts: (state) => state.shifts.filter(s => s.is_active !== false),
        shiftsMap: (state) => {
            const map = {};
            state.shifts.forEach(s => { map[s.id] = s; });
            return map;
        },
        holidaysMap: (state) => {
            const map = {};
            state.holidays.forEach(h => {
                if (!map[h.date]) map[h.date] = [];
                map[h.date].push(h);
            });
            return map;
        },
        monthHolidays: (state) => {
            const prefix = `${state.calendarYear}-${String(state.calendarMonth + 1).padStart(2, '0')}`;
            return state.holidays.filter(h => h.date && h.date.startsWith(prefix));
        },
    },

    actions: {
        setCalendarView(mode) {
            this.calendarViewMode = mode;
        },

        navigateMonth(delta) {
            let m = this.calendarMonth + delta;
            let y = this.calendarYear;
            if (m < 0) {
                m = 11;
                y -= 1;
            } else if (m > 11) {
                m = 0;
                y += 1;
            }
            this.calendarMonth = m;
            this.calendarYear = y;
            this.fetchHolidays();
        },

        setToday() {
            const now = new Date();
            this.calendarMonth = now.getMonth();
            this.calendarYear = now.getFullYear();
            this.fetchHolidays();
        },

        // --- Shifts ---
        async fetchShifts() {
            this.loading = true;
            try {
                const res = await apiClient.get('/shifts');
                this.shifts = res.data.data || res.data || [];
            } catch (err) {
                console.warn('Failed to load shifts:', err);
            } finally {
                this.loading = false;
            }
        },

        async createShift(payload) {
            this.saving = true;
            try {
                const res = await apiClient.post('/shifts', payload);
                const shift = res.data.data || res.data;
                notify.success('Shift Created', `Shift "${shift.name}" created successfully.`);
                await this.fetchShifts();
                return shift;
            } catch (err) {
                notify.error('Creation Failed', err.response?.data?.message || 'Could not create shift.');
                throw err;
            } finally {
                this.saving = false;
            }
        },

        async updateShift(id, payload) {
            this.saving = true;
            try {
                const res = await apiClient.put(`/shifts/${id}`, payload);
                const shift = res.data.data || res.data;
                notify.success('Shift Updated', `Shift "${shift.name}" updated successfully.`);
                await this.fetchShifts();
                return shift;
            } catch (err) {
                notify.error('Update Failed', err.response?.data?.message || 'Could not update shift.');
                throw err;
            } finally {
                this.saving = false;
            }
        },

        async deleteShift(id) {
            try {
                await apiClient.delete(`/shifts/${id}`);
                notify.toast('Shift deleted successfully.', 'info');
                await this.fetchShifts();
            } catch (err) {
                notify.error('Delete Failed', err.response?.data?.message || 'Could not delete shift.');
                throw err;
            }
        },

        async bulkAssignShift(payload) {
            this.saving = true;
            try {
                const res = await apiClient.post('/shifts/bulk-assign', payload);
                notify.success('Schedule Assigned', 'Shift assignment applied successfully.');
                return res.data;
            } catch (err) {
                notify.error('Assignment Error', err.response?.data?.message || 'Failed to assign shifts.');
                throw err;
            } finally {
                this.saving = false;
            }
        },

        // --- Holidays ---
        async fetchHolidays() {
            try {
                const res = await apiClient.get('/holidays', {
                    params: { year: this.calendarYear }
                });
                this.holidays = res.data.data || res.data || [];
            } catch (err) {
                console.warn('Failed to load holidays:', err);
            }
        },

        async createHoliday(payload) {
            this.saving = true;
            try {
                const res = await apiClient.post('/holidays', payload);
                const holiday = res.data.data || res.data;
                notify.success('Holiday Added', `Holiday "${holiday.name}" saved.`);
                await this.fetchHolidays();
                return holiday;
            } catch (err) {
                notify.error('Holiday Error', err.response?.data?.message || 'Failed to add holiday.');
                throw err;
            } finally {
                this.saving = false;
            }
        },

        async updateHoliday(id, payload) {
            this.saving = true;
            try {
                const res = await apiClient.put(`/holidays/${id}`, payload);
                const holiday = res.data.data || res.data;
                notify.success('Holiday Updated', `Holiday "${holiday.name}" updated.`);
                await this.fetchHolidays();
                return holiday;
            } catch (err) {
                notify.error('Update Error', err.response?.data?.message || 'Failed to update holiday.');
                throw err;
            } finally {
                this.saving = false;
            }
        },

        async deleteHoliday(id) {
            try {
                await apiClient.delete(`/holidays/${id}`);
                notify.toast('Holiday removed.', 'info');
                await this.fetchHolidays();
            } catch (err) {
                notify.error('Delete Error', err.response?.data?.message || 'Could not delete holiday.');
                throw err;
            }
        },
    },
});
