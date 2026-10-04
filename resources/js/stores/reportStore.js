import { defineStore } from 'pinia';
import apiClient from '../api/client';
import notify from '../utils/notify';

export const useReportStore = defineStore('report', {
    state: () => ({
        loading: false,
        dailyReportData: null,
        monthlyReportData: null,
        departmentReportData: null,
        payrollData: null,
    }),

    actions: {
        async fetchDailyReport(date, departmentId = null) {
            this.loading = true;
            try {
                const params = { date };
                if (departmentId) params.department_id = departmentId;
                const res = await apiClient.get('/reports/attendance/daily', { params });
                this.dailyReportData = res.data;
                return res.data;
            } catch (err) {
                notify.error('Report Error', err.response?.data?.message || 'Failed to load daily report.');
            } finally {
                this.loading = false;
            }
        },

        async fetchMonthlyReport(month, year, departmentId = null) {
            this.loading = true;
            try {
                const params = { month, year };
                if (departmentId) params.department_id = departmentId;
                const res = await apiClient.get('/reports/attendance/monthly', { params });
                this.monthlyReportData = res.data;
                return res.data;
            } catch (err) {
                notify.error('Report Error', err.response?.data?.message || 'Failed to load monthly report.');
            } finally {
                this.loading = false;
            }
        },

        async fetchDepartmentReport(startDate, endDate) {
            this.loading = true;
            try {
                const res = await apiClient.get('/reports/attendance/department', {
                    params: { start_date: startDate, end_date: endDate },
                });
                this.departmentReportData = res.data;
                return res.data;
            } catch (err) {
                notify.error('Report Error', 'Failed to load department analytics.');
            } finally {
                this.loading = false;
            }
        },

        async exportReport(type, format = 'csv', params = {}) {
            try {
                const res = await apiClient.get('/reports/attendance/export', {
                    params: { type, format, ...params },
                    responseType: 'blob',
                });
                const blob = new Blob([res.data], { type: 'text/csv;charset=utf-8;' });
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.setAttribute('download', `${type}_report_${new Date().toISOString().slice(0, 10)}.${format}`);
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                URL.revokeObjectURL(url);
                notify.toast('Report downloaded successfully.', 'success');
            } catch (err) {
                notify.error('Export Failed', 'Failed to export report data.');
            }
        },

        async exportPayroll(month, year, format = 'csv') {
            try {
                const res = await apiClient.get('/payroll/export', {
                    params: { month, year, format },
                    responseType: format === 'csv' ? 'blob' : 'json',
                });

                if (format === 'csv') {
                    const blob = new Blob([res.data], { type: 'text/csv;charset=utf-8;' });
                    const url = URL.createObjectURL(blob);
                    const link = document.createElement('a');
                    link.href = url;
                    link.setAttribute('download', `payroll_${year}_${month}.csv`);
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    URL.revokeObjectURL(url);
                    notify.toast('Payroll CSV exported.', 'success');
                } else {
                    this.payrollData = res.data;
                }
            } catch (err) {
                notify.error('Payroll Export Failed', 'Could not export payroll data.');
            }
        },
    },
});
