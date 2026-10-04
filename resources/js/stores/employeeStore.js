import { defineStore } from 'pinia';
import apiClient from '../api/client';
import notify from '../utils/notify';

export const useEmployeeStore = defineStore('employee', {
    state: () => ({
        employees: [],
        pagination: {
            current_page: 1,
            last_page: 1,
            per_page: 15,
            total: 0,
            from: 0,
            to: 0,
        },
        filters: {
            search: '',
            department_id: '',
            designation_id: '',
            location_id: '',
            employment_status: '',
            employment_type: '',
        },
        currentEmployee: null,
        loading: false,
        saving: false,
        deleting: false,
        error: null,
        departments: [],
        designations: [],
        locations: [],
        managers: [],
        shifts: [],
        viewMode: localStorage.getItem('employee_view_mode') || 'table', // 'table' | 'grid'
    }),

    getters: {
        hasActiveFilters: (state) => {
            return !!(
                state.filters.search ||
                state.filters.department_id ||
                state.filters.designation_id ||
                state.filters.location_id ||
                state.filters.employment_status ||
                state.filters.employment_type
            );
        },
        totalCount: (state) => state.pagination.total || state.employees.length,
        activeCount: (state) => state.employees.filter(e => e.employment_status === 'active').length,
    },

    actions: {
        setViewMode(mode) {
            this.viewMode = mode;
            localStorage.setItem('employee_view_mode', mode);
        },

        setFilter(key, value) {
            this.filters[key] = value;
            this.fetchEmployees(1);
        },

        resetFilters() {
            this.filters = {
                search: '',
                department_id: '',
                designation_id: '',
                location_id: '',
                employment_status: '',
                employment_type: '',
            };
            this.fetchEmployees(1);
        },

        async fetchMetadata() {
            try {
                const [deptRes, desigRes, locRes, shiftRes] = await Promise.allSettled([
                    apiClient.get('/departments'),
                    apiClient.get('/designations'),
                    apiClient.get('/locations'),
                    apiClient.get('/shifts'),
                ]);

                if (deptRes.status === 'fulfilled') {
                    this.departments = deptRes.value.data.data || deptRes.value.data || [];
                }
                if (desigRes.status === 'fulfilled') {
                    this.designations = desigRes.value.data.data || desigRes.value.data || [];
                }
                if (locRes.status === 'fulfilled') {
                    this.locations = locRes.value.data.data || locRes.value.data || [];
                }
                if (shiftRes.status === 'fulfilled') {
                    this.shifts = shiftRes.value.data.data || shiftRes.value.data || [];
                }
            } catch (err) {
                console.warn('Failed to load employee metadata:', err);
            }
        },

        async fetchEmployees(page = 1) {
            this.loading = true;
            this.error = null;

            const params = {
                page,
                per_page: this.pagination.per_page,
            };

            if (this.filters.search) params.search = this.filters.search;
            if (this.filters.department_id) params.department_id = this.filters.department_id;
            if (this.filters.designation_id) params.designation_id = this.filters.designation_id;
            if (this.filters.location_id) params.location_id = this.filters.location_id;
            if (this.filters.employment_status) params.status = this.filters.employment_status;
            if (this.filters.employment_type) params.employment_type = this.filters.employment_type;

            try {
                const res = await apiClient.get('/employees', { params });
                const data = res.data;

                if (Array.isArray(data)) {
                    this.employees = data;
                    this.pagination.total = data.length;
                    this.pagination.current_page = 1;
                    this.pagination.last_page = 1;
                } else if (data.data && Array.isArray(data.data)) {
                    this.employees = data.data;
                    this.pagination.current_page = data.current_page || 1;
                    this.pagination.last_page = data.last_page || 1;
                    this.pagination.per_page = data.per_page || 15;
                    this.pagination.total = data.total || data.data.length;
                    this.pagination.from = data.from || 1;
                    this.pagination.to = data.to || data.data.length;
                } else {
                    this.employees = [];
                }
            } catch (err) {
                this.error = err.response?.data?.message || 'Failed to fetch employees';
                notify.error('Employee Fetch Failed', this.error);
            } finally {
                this.loading = false;
            }
        },

        async fetchEmployee(id) {
            this.loading = true;
            try {
                const res = await apiClient.get(`/employees/${id}`);
                this.currentEmployee = res.data.data || res.data;
                return this.currentEmployee;
            } catch (err) {
                notify.error('Failed to load employee profile');
                throw err;
            } finally {
                this.loading = false;
            }
        },

        async createEmployee(payload) {
            this.saving = true;
            try {
                const res = await apiClient.post('/employees', payload);
                const created = res.data.data || res.data;
                notify.success('Employee Created', `${created.first_name} ${created.last_name || ''} enrolled successfully.`);
                await this.fetchEmployees(1);
                return created;
            } catch (err) {
                const msg = err.response?.data?.message || 'Failed to create employee.';
                notify.error('Creation Error', msg);
                throw err;
            } finally {
                this.saving = false;
            }
        },

        async updateEmployee(id, payload) {
            this.saving = true;
            try {
                const res = await apiClient.put(`/employees/${id}`, payload);
                const updated = res.data.data || res.data;
                notify.success('Employee Updated', 'Employee details saved successfully.');
                await this.fetchEmployees(this.pagination.current_page);
                if (this.currentEmployee?.id === id) {
                    this.currentEmployee = updated;
                }
                return updated;
            } catch (err) {
                const msg = err.response?.data?.message || 'Failed to update employee.';
                notify.error('Update Error', msg);
                throw err;
            } finally {
                this.saving = false;
            }
        },

        async deleteEmployee(id) {
            this.deleting = true;
            try {
                await apiClient.delete(`/employees/${id}`);
                notify.toast('Employee record deleted/archived.', 'info');
                await this.fetchEmployees(this.pagination.current_page);
            } catch (err) {
                notify.error('Delete Failed', err.response?.data?.message || 'Unable to delete employee.');
                throw err;
            } finally {
                this.deleting = false;
            }
        },

        async assignShift(employeeId, shiftData) {
            try {
                await apiClient.post(`/employees/${employeeId}/assign-shift`, shiftData);
                notify.success('Shift Assigned', 'Employee shift schedule updated.');
                await this.fetchEmployees(this.pagination.current_page);
            } catch (err) {
                notify.error('Shift Assignment Error', err.response?.data?.message || 'Unable to assign shift.');
                throw err;
            }
        },

        async importCsv(file) {
            const formData = new FormData();
            formData.append('file', file);

            try {
                const res = await apiClient.post('/employees/import', formData, {
                    headers: { 'Content-Type': 'multipart/form-data' },
                });
                notify.success('Import Successful', `${res.data.imported_count || 'Employees'} records successfully imported.`);
                await this.fetchEmployees(1);
                return res.data;
            } catch (err) {
                notify.error('Import Failed', err.response?.data?.message || 'Failed to parse CSV file.');
                throw err;
            }
        },

        async exportCsv() {
            try {
                const res = await apiClient.get('/employees/export', {
                    responseType: 'blob',
                    params: this.filters,
                });

                const blob = new Blob([res.data], { type: 'text/csv;charset=utf-8;' });
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.setAttribute('download', `employees_export_${new Date().toISOString().slice(0, 10)}.csv`);
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                URL.revokeObjectURL(url);
                notify.toast('Employee export downloaded.', 'success');
            } catch (err) {
                notify.error('Export Failed', 'Failed to generate employee CSV export.');
            }
        },
    },
});
