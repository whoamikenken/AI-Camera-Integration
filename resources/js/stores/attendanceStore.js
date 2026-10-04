import { defineStore } from 'pinia';
import apiClient from '../api/client';
import notify from '../utils/notify';

export const useAttendanceStore = defineStore('attendance', {
    state: () => ({
        dailyRoster: [],
        records: [],
        punches: [],
        selectedDate: new Date().toISOString().slice(0, 10),
        selectedDepartmentId: '',
        selectedStatus: '',
        searchQuery: '',
        loading: false,
        finalizing: false,
        stats: {
            total_employees: 0,
            present: 0,
            absent: 0,
            late: 0,
            on_leave: 0,
            early_out: 0,
            attendance_rate: 0,
        },
        pagination: {
            current_page: 1,
            last_page: 1,
            per_page: 20,
            total: 0,
        },
        recentLivePunches: [],
    }),

    getters: {
        filteredRoster: (state) => {
            return state.dailyRoster.filter(item => {
                const name = `${item.employee?.first_name || ''} ${item.employee?.last_name || ''} ${item.employee?.personnel?.name || ''}`.toLowerCase();
                const code = (item.employee?.employee_code || '').toLowerCase();
                const query = state.searchQuery.toLowerCase();
                const matchesSearch = !query || name.includes(query) || code.includes(query);
                const matchesDept = !state.selectedDepartmentId || item.employee?.department_id == state.selectedDepartmentId;
                const matchesStatus = !state.selectedStatus || item.status === state.selectedStatus;
                return matchesSearch && matchesDept && matchesStatus;
            });
        },
    },

    actions: {
        setDate(date) {
            this.selectedDate = date;
            this.fetchDailyAttendance();
        },

        async fetchDailyAttendance() {
            this.loading = true;
            try {
                const params = {
                    date: this.selectedDate,
                };
                if (this.selectedDepartmentId) params.department_id = this.selectedDepartmentId;
                if (this.selectedStatus) params.status = this.selectedStatus;

                const res = await apiClient.get('/attendance/daily', { params });
                const data = res.data;

                if (Array.isArray(data)) {
                    this.dailyRoster = data;
                } else if (data.roster) {
                    this.dailyRoster = data.roster;
                    if (data.stats) {
                        this.stats = { ...this.stats, ...data.stats };
                    }
                } else if (data.data) {
                    this.dailyRoster = data.data;
                } else {
                    this.dailyRoster = [];
                }

                // Compute local stats if not from server
                if (!data.stats) {
                    this.computeLocalStats();
                }
            } catch (err) {
                console.error('Failed to fetch daily attendance:', err);
                notify.error('Attendance Fetch Error', err.response?.data?.message || 'Unable to load attendance records.');
            } finally {
                this.loading = false;
            }
        },

        computeLocalStats() {
            const total = this.dailyRoster.length;
            const present = this.dailyRoster.filter(r => ['present', 'late', 'early_out', 'late_and_early_out', 'half_day'].includes(r.status)).length;
            const absent = this.dailyRoster.filter(r => r.status === 'absent').length;
            const late = this.dailyRoster.filter(r => r.is_late || ['late', 'late_and_early_out'].includes(r.status)).length;
            const on_leave = this.dailyRoster.filter(r => r.status === 'on_leave').length;
            const early_out = this.dailyRoster.filter(r => r.is_early_out || ['early_out', 'late_and_early_out'].includes(r.status)).length;

            this.stats = {
                total_employees: total,
                present,
                absent,
                late,
                on_leave,
                early_out,
                attendance_rate: total > 0 ? Math.round((present / total) * 100) : 0,
            };
        },

        async fetchEmployeePunches(employeeId, date = null) {
            try {
                const params = { employee_id: employeeId };
                if (date) params.date = date;
                const res = await apiClient.get('/attendance/punches', { params });
                this.punches = res.data.data || res.data || [];
                return this.punches;
            } catch (err) {
                notify.error('Punches Fetch Error', 'Unable to load punch log.');
                return [];
            }
        },

        async submitManualEntry(payload) {
            try {
                const res = await apiClient.post('/attendance/manual-entry', payload);
                notify.success('Manual Entry Added', 'Attendance punch recorded successfully.');
                await this.fetchDailyAttendance();
                return res.data;
            } catch (err) {
                notify.error('Entry Failed', err.response?.data?.message || 'Could not record attendance entry.');
                throw err;
            }
        },

        async overrideStatus(recordId, payload) {
            try {
                const res = await apiClient.put(`/attendance/${recordId}/override`, payload);
                notify.success('Status Overridden', 'Attendance status updated successfully.');
                await this.fetchDailyAttendance();
                return res.data;
            } catch (err) {
                notify.error('Override Failed', err.response?.data?.message || 'Could not override status.');
                throw err;
            }
        },

        async triggerDailyFinalizer(date = null) {
            this.finalizing = true;
            try {
                const res = await apiClient.post('/attendance/finalize-daily', {
                    date: date || this.selectedDate,
                });
                notify.success('Finalization Complete', res.data.message || 'Daily attendance records finalized.');
                await this.fetchDailyAttendance();
                return res.data;
            } catch (err) {
                notify.error('Finalization Error', err.response?.data?.message || 'Failed to finalize attendance.');
                throw err;
            } finally {
                this.finalizing = false;
            }
        },

        handleLivePunch(eventData) {
            if (!eventData || !eventData.punch) return;
            const punch = eventData.punch;
            this.recentLivePunches.unshift({
                ...punch,
                received_at: new Date().toISOString(),
            });
            if (this.recentLivePunches.length > 30) {
                this.recentLivePunches.pop();
            }

            // Update roster item in-place if viewing today
            const today = new Date().toISOString().slice(0, 10);
            if (this.selectedDate === today && eventData.record) {
                const idx = this.dailyRoster.findIndex(r => r.employee_id === eventData.record.employee_id);
                if (idx !== -1) {
                    this.dailyRoster[idx] = { ...this.dailyRoster[idx], ...eventData.record };
                    this.computeLocalStats();
                } else {
                    this.fetchDailyAttendance();
                }
            }
        },
    },
});
