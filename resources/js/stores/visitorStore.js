import { defineStore } from 'pinia';
import apiClient from '../api/client';
import notify from '../utils/notify';

export const useVisitorStore = defineStore('visitor', {
    state: () => ({
        visits: [],
        visitors: [],
        watchlist: [],
        loading: false,
        saving: false,
        filters: {
            status: '',
            search: '',
            date: '',
        },
        pagination: {
            current_page: 1,
            last_page: 1,
            per_page: 15,
            total: 0,
        },
        stats: {
            expected_today: 0,
            checked_in: 0,
            checked_out: 0,
            overdue: 0,
            no_show: 0,
            total: 0,
        },
    }),

    getters: {
        activeCheckedInVisits: (state) => {
            return state.visits.filter(v => v.status === 'checked_in');
        },
    },

    actions: {
        async fetchVisits(page = 1) {
            this.loading = true;
            try {
                const params = {
                    page,
                    per_page: this.pagination.per_page,
                    ...this.filters,
                };
                const res = await apiClient.get('/visits', { params });
                const data = res.data;

                if (Array.isArray(data)) {
                    this.visits = data;
                } else if (data.data && Array.isArray(data.data)) {
                    this.visits = data.data;
                    this.pagination.current_page = data.current_page || 1;
                    this.pagination.last_page = data.last_page || 1;
                    this.pagination.total = data.total || data.data.length;
                } else {
                    this.visits = [];
                }

                const serverStats = data.stats || data.meta?.stats || data.summary;
                if (serverStats) {
                    this.stats = {
                        expected_today: Number(serverStats.expected_today ?? 0),
                        checked_in: Number(serverStats.checked_in ?? 0),
                        checked_out: Number(serverStats.checked_out ?? 0),
                        overdue: Number(serverStats.overdue ?? serverStats.overstayed ?? 0),
                        no_show: Number(serverStats.no_show ?? 0),
                        total: Number(serverStats.total ?? 0),
                    };
                } else {
                    this.computeVisitorStats();
                }
            } catch (err) {
                notify.error('Visits Fetch Error', err.response?.data?.message || 'Unable to load visits.');
            } finally {
                this.loading = false;
            }
        },

        computeVisitorStats() {
            const checkedIn = this.visits.filter(v => v.status === 'checked_in').length;
            const checkedOut = this.visits.filter(v => v.status === 'checked_out').length;
            const expected = this.visits.filter(v => v.status === 'expected').length;
            const noShow = this.visits.filter(v => v.status === 'no_show').length;
            const overdue = this.visits.filter(v => {
                if (v.status === 'overstayed' || v.is_overstay) return true;
                if (v.status === 'checked_in' && v.expected_departure) {
                    return new Date(v.expected_departure) < new Date();
                }
                return false;
            }).length;

            this.stats = {
                expected_today: expected,
                checked_in: checkedIn,
                checked_out: checkedOut,
                overdue: overdue,
                no_show: noShow,
                total: this.visits.length,
            };
        },

        async fetchStats() {
            try {
                const res = await apiClient.get('/visits/stats');
                const s = res.data?.stats || res.data?.data || res.data;
                if (s) {
                    this.stats = {
                        expected_today: Number(s.expected_today ?? 0),
                        checked_in: Number(s.checked_in ?? 0),
                        checked_out: Number(s.checked_out ?? 0),
                        overdue: Number(s.overdue ?? s.overstayed ?? 0),
                        no_show: Number(s.no_show ?? 0),
                        total: Number(s.total ?? 0),
                    };
                }
            } catch (err) {
                console.error('Failed to load visitor stats:', err);
            }
        },

        async fetchVisitors(search = '') {
            try {
                const res = await apiClient.get('/visitors', { params: { search } });
                this.visitors = res.data.data || res.data || [];
                return this.visitors;
            } catch (err) {
                console.error('Failed to load visitors directory:', err);
                return [];
            }
        },

        async createVisitor(payload) {
            this.saving = true;
            try {
                const res = await apiClient.post('/visitors', payload);
                notify.success('Visitor Registered', 'Visitor identity created.');
                return res.data.data || res.data;
            } catch (err) {
                notify.error('Registration Error', err.response?.data?.message || 'Could not register visitor.');
                throw err;
            } finally {
                this.saving = false;
            }
        },

        async preRegisterVisit(payload) {
            this.saving = true;
            try {
                const res = await apiClient.post('/visits/pre-register', payload);
                notify.success('Visit Pre-registered', 'Scheduled visit has been created.');
                await this.fetchVisits(1);
                return res.data;
            } catch (err) {
                notify.error('Pre-registration Failed', err.response?.data?.message || 'Could not pre-register visit.');
                throw err;
            } finally {
                this.saving = false;
            }
        },

        async checkInVisit(visitId, payload = {}) {
            try {
                const res = await apiClient.put(`/visits/${visitId}/check-in`, payload);
                notify.success('Visitor Checked In', 'Biometric whitelist access provisioned to cameras.');
                await this.fetchVisits(this.pagination.current_page);
                return res.data;
            } catch (err) {
                notify.error('Check-In Failed', err.response?.data?.message || 'Could not check in visitor.');
                throw err;
            }
        },

        async checkOutVisit(visitId) {
            try {
                const res = await apiClient.put(`/visits/${visitId}/check-out`);
                notify.toast('Visitor checked out. Camera access revoked.', 'info');
                await this.fetchVisits(this.pagination.current_page);
                return res.data;
            } catch (err) {
                notify.error('Check-Out Failed', err.response?.data?.message || 'Could not check out visitor.');
                throw err;
            }
        },

        async toggleWatchlist(visitorId, isBlocked, reason = '') {
            try {
                const res = await apiClient.post(`/visitors/${visitorId}/block`, {
                    is_blocked: isBlocked,
                    block_reason: reason,
                });
                notify.success('Watchlist Updated', res.data.message || 'Security status updated.');
                await this.fetchVisits(this.pagination.current_page);
                return res.data;
            } catch (err) {
                notify.error('Watchlist Error', err.response?.data?.message || 'Failed to update watchlist.');
                throw err;
            }
        },

        handleLiveVisitorCheckIn(visitData) {
            if (!visitData) return;
            const idx = this.visits.findIndex(v => v.id === visitData.id);
            if (idx !== -1) {
                this.visits[idx] = { ...this.visits[idx], ...visitData };
            } else {
                this.visits.unshift(visitData);
            }
            this.computeVisitorStats();
            notify.toast(`Visitor Check-in: ${visitData.visitor_name || 'Guest'} arrived.`, 'info');
        },

        handleLiveVisitorCheckOut(visitData) {
            if (!visitData) return;
            const idx = this.visits.findIndex(v => v.id === visitData.id);
            if (idx !== -1) {
                this.visits[idx] = { ...this.visits[idx], ...visitData };
            }
            this.computeVisitorStats();
            notify.toast(`Visitor Check-out: ${visitData.visitor_name || 'Guest'} departed.`, 'info');
        },

        async fetchOverstayedVisits() {
            try {
                const res = await apiClient.get('/visits/overstayed');
                return res.data.data || res.data || [];
            } catch (err) {
                console.error('Failed to load overstayed visits:', err);
                return [];
            }
        },

        async cancelVisit(visitId, reason = '') {
            try {
                const res = await apiClient.post(`/visits/${visitId}/cancel`, {
                    cancellation_reason: reason,
                    reason: reason,
                });
                notify.toast('Visit cancelled. Camera face revoked.', 'info');
                await this.fetchVisits(this.pagination.current_page);
                return res.data;
            } catch (err) {
                notify.error('Cancellation Failed', err.response?.data?.message || 'Could not cancel visit.');
                throw err;
            }
        },
    },
});
