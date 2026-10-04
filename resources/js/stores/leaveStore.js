import { defineStore } from 'pinia';
import apiClient from '../api/client';
import notify from '../utils/notify';

export const useLeaveStore = defineStore('leave', {
    state: () => ({
        leaveTypes: [],
        leaveBalances: [],
        leaveRequests: [],
        regularizationRequests: [],
        loading: false,
        saving: false,
        filters: {
            status: '',
            leave_type_id: '',
            employee_id: '',
        },
        pagination: {
            current_page: 1,
            last_page: 1,
            per_page: 15,
            total: 0,
        },
    }),

    getters: {
        pendingRequestsCount: (state) => {
            return state.leaveRequests.filter(r => r.status === 'pending').length;
        },
        pendingRegularizationsCount: (state) => {
            return state.regularizationRequests.filter(r => r.status === 'pending').length;
        },
    },

    actions: {
        async fetchLeaveTypes() {
            try {
                const res = await apiClient.get('/leave-types');
                this.leaveTypes = res.data.data || res.data || [];
            } catch (err) {
                console.error('Failed to load leave types:', err);
            }
        },

        async fetchLeaveBalances(employeeId = null, year = null) {
            try {
                const params = {};
                if (employeeId) params.employee_id = employeeId;
                if (year) params.year = year;
                const res = await apiClient.get('/leave-balances', { params });
                this.leaveBalances = res.data.data || res.data || [];
            } catch (err) {
                console.error('Failed to load leave balances:', err);
            }
        },

        async fetchLeaveRequests(page = 1) {
            this.loading = true;
            try {
                const params = {
                    page,
                    per_page: this.pagination.per_page,
                    ...this.filters,
                };
                const res = await apiClient.get('/leave-requests', { params });
                const data = res.data;

                if (Array.isArray(data)) {
                    this.leaveRequests = data;
                } else if (data.data && Array.isArray(data.data)) {
                    this.leaveRequests = data.data;
                    this.pagination.current_page = data.current_page || 1;
                    this.pagination.last_page = data.last_page || 1;
                    this.pagination.total = data.total || data.data.length;
                } else {
                    this.leaveRequests = [];
                }
            } catch (err) {
                notify.error('Leave Requests Fetch Error', err.response?.data?.message || 'Unable to load leave requests.');
            } finally {
                this.loading = false;
            }
        },

        async createLeaveRequest(payload) {
            this.saving = true;
            try {
                const res = await apiClient.post('/leave-requests', payload);
                notify.success('Leave Request Submitted', 'Your leave request has been submitted for approval.');
                await this.fetchLeaveRequests(1);
                return res.data;
            } catch (err) {
                notify.error('Submission Failed', err.response?.data?.message || 'Could not submit leave request.');
                throw err;
            } finally {
                this.saving = false;
            }
        },

        async approveLeaveRequest(id, remarks = '') {
            try {
                const res = await apiClient.put(`/leave-requests/${id}/approve`, { remarks });
                notify.success('Request Approved', 'Leave request has been approved.');
                await this.fetchLeaveRequests(this.pagination.current_page);
                return res.data;
            } catch (err) {
                notify.error('Approval Failed', err.response?.data?.message || 'Could not approve leave request.');
                throw err;
            }
        },

        async rejectLeaveRequest(id, remarks = '') {
            try {
                const res = await apiClient.put(`/leave-requests/${id}/reject`, { remarks });
                notify.toast('Leave request rejected.', 'info');
                await this.fetchLeaveRequests(this.pagination.current_page);
                return res.data;
            } catch (err) {
                notify.error('Rejection Failed', err.response?.data?.message || 'Could not reject leave request.');
                throw err;
            }
        },

        async fetchRegularizations(page = 1) {
            try {
                const res = await apiClient.get('/regularization-requests', { params: { page } });
                this.regularizationRequests = res.data.data || res.data || [];
            } catch (err) {
                console.error('Failed to load regularizations:', err);
            }
        },

        async approveRegularization(id, remarks = '') {
            try {
                const res = await apiClient.put(`/regularization-requests/${id}/approve`, { remarks });
                notify.success('Regularization Approved', 'Punches updated and daily attendance recalculated.');
                await this.fetchRegularizations();
                return res.data;
            } catch (err) {
                notify.error('Approval Failed', err.response?.data?.message || 'Could not approve regularization.');
                throw err;
            }
        },

        async rejectRegularization(id, remarks = '') {
            try {
                const res = await apiClient.put(`/regularization-requests/${id}/reject`, { remarks });
                notify.toast('Regularization rejected.', 'info');
                await this.fetchRegularizations();
                return res.data;
            } catch (err) {
                notify.error('Rejection Failed', err.response?.data?.message || 'Could not reject regularization.');
                throw err;
            }
        },
    },
});
