import { defineStore } from 'pinia';
import apiClient from '../api/client';

let audioContextSingleton = null;

function normalizePayload(e) {
    if (!e) return null;
    let data = e;
    if (typeof data === 'string') {
        try {
            data = JSON.parse(data);
        } catch (err) {
            return null;
        }
    }
    if (data && typeof data.data === 'string') {
        try {
            data = JSON.parse(data.data);
        } catch (err) {}
    } else if (data && data.data && typeof data.data === 'object' && !Array.isArray(data.data)) {
        data = data.data;
    }
    return data;
}

export const useCameraStore = defineStore('camera', {
    state: () => ({
        liveLogs: [],
        strangerSnaps: [],
        deviceAlerts: [],
        devices: [],
        stats: {
            telemetry: {
                total_scans_today: 0,
                allowed_today: 0,
                rejected_today: 0,
                strangers_today: 0,
                alerts_today: 0,
                critical_alerts_today: 0,
                unresolved_alerts: 0,
            },
            devices: {
                total: 0,
                online: 0,
                offline: 0,
            },
            personnel: {
                total: 0,
                whitelisted: 0,
                blacklisted: 0,
            },
            sync: {
                pending: 0,
                failed: 0,
            }
        },
        wsConnected: false,
        soundEnabled: false,
        statsLoading: false,
    }),

    actions: {
        async fetchStats() {
            this.statsLoading = true;
            try {
                const res = await apiClient.get('/api/stats');
                if (res.data) {
                    this.stats = {
                        telemetry: {
                            total_scans_today: res.data.telemetry?.total_scans_today || 0,
                            allowed_today: res.data.telemetry?.allowed_today || 0,
                            rejected_today: res.data.telemetry?.rejected_today || 0,
                            strangers_today: res.data.telemetry?.strangers_today || 0,
                            alerts_today: res.data.telemetry?.alerts_today || 0,
                            critical_alerts_today: res.data.telemetry?.critical_alerts_today || 0,
                            unresolved_alerts: res.data.telemetry?.unresolved_alerts || 0,
                        },
                        devices: {
                            total: res.data.devices?.total || 0,
                            online: res.data.devices?.online || 0,
                            offline: res.data.devices?.offline || 0,
                        },
                        personnel: {
                            total: res.data.personnel?.total || 0,
                            whitelisted: res.data.personnel?.whitelisted || 0,
                            blacklisted: res.data.personnel?.blacklisted || 0,
                        },
                        sync: {
                            pending: res.data.sync?.pending || 0,
                            failed: res.data.sync?.failed || 0,
                        },
                    };
                }
            } catch (err) {
                console.error('Failed to fetch stats:', err);
            } finally {
                this.statsLoading = false;
            }
        },

        async fetchDevices() {
            try {
                const res = await apiClient.get('/api/devices');
                this.devices = res.data || [];
            } catch (err) {
                console.error('Failed to fetch devices:', err);
            }
        },

        async fetchRecentLogs() {
            try {
                const res = await apiClient.get('/api/access-logs?per_page=25');
                this.liveLogs = res.data?.data || [];
            } catch (err) {
                console.error('Failed to fetch access logs:', err);
            }
        },

        addLiveLog(rawLog) {
            const log = normalizePayload(rawLog);
            if (!log) return;

            if (!this.stats.telemetry) {
                this.stats.telemetry = {
                    total_scans_today: 0,
                    allowed_today: 0,
                    rejected_today: 0,
                    strangers_today: 0,
                    alerts_today: 0,
                    critical_alerts_today: 0,
                    unresolved_alerts: 0,
                };
            }

            const index = this.liveLogs.findIndex(
                l => (l.id && String(l.id) === String(log.id)) ||
                     (l.captured_at && log.captured_at && l.captured_at === log.captured_at && String(l.device_id) === String(log.device_id))
            );

            if (index !== -1) {
                this.liveLogs[index] = { ...this.liveLogs[index], ...log };
                this.liveLogs = [...this.liveLogs];
            } else {
                this.liveLogs = [log, ...this.liveLogs];
                if (this.liveLogs.length > 50) {
                    this.liveLogs.pop();
                }

                // Increment baseline telemetry counters reactively
                this.stats.telemetry.total_scans_today = (this.stats.telemetry.total_scans_today || 0) + 1;
                if (Number(log.verify_status) === 1) {
                    this.stats.telemetry.allowed_today = (this.stats.telemetry.allowed_today || 0) + 1;
                } else if (Number(log.verify_status) === 2) {
                    this.stats.telemetry.rejected_today = (this.stats.telemetry.rejected_today || 0) + 1;
                }
            }

            if (log.device_id) {
                const dev = this.devices.find(d => String(d.device_id) === String(log.device_id));
                if (dev) {
                    dev.access_logs_count = (dev.access_logs_count || 0) + 1;
                }
            }

            if (this.soundEnabled && Number(log.verify_status) === 2) {
                this.playAlertSound();
            }
        },

        addStrangerSnap(rawSnap) {
            const snap = normalizePayload(rawSnap);
            if (!snap) return;

            if (!this.stats.telemetry) {
                this.stats.telemetry = {
                    total_scans_today: 0,
                    allowed_today: 0,
                    rejected_today: 0,
                    strangers_today: 0,
                    alerts_today: 0,
                    critical_alerts_today: 0,
                    unresolved_alerts: 0,
                };
            }

            const exists = this.strangerSnaps.some(
                s => (s.id && String(s.id) === String(snap.id)) ||
                     (s.captured_at && snap.captured_at && s.captured_at === snap.captured_at && String(s.device_id) === String(snap.device_id))
            );
            if (!exists) {
                this.strangerSnaps.unshift(snap);
                if (this.strangerSnaps.length > 30) {
                    this.strangerSnaps.pop();
                }
                this.stats.telemetry.strangers_today = (this.stats.telemetry.strangers_today || 0) + 1;
            }

            if (snap.device_id) {
                const dev = this.devices.find(d => String(d.device_id) === String(snap.device_id));
                if (dev) {
                    dev.stranger_snaps_count = (dev.stranger_snaps_count || 0) + 1;
                }
            }
        },

        addDeviceAlert(rawAlert) {
            const alert = normalizePayload(rawAlert);
            if (!alert) return;

            if (!this.stats.telemetry) {
                this.stats.telemetry = {
                    total_scans_today: 0,
                    allowed_today: 0,
                    rejected_today: 0,
                    strangers_today: 0,
                    alerts_today: 0,
                    critical_alerts_today: 0,
                    unresolved_alerts: 0,
                };
            }

            const exists = this.deviceAlerts.some(
                a => (a.id && String(a.id) === String(alert.id)) ||
                     (a.captured_at && alert.captured_at && a.captured_at === alert.captured_at && String(a.device_id) === String(alert.device_id) && a.alert_type === alert.alert_type)
            );
            if (!exists) {
                this.deviceAlerts.unshift(alert);
                if (this.deviceAlerts.length > 50) {
                    this.deviceAlerts.pop();
                }

                this.stats.telemetry.alerts_today = (this.stats.telemetry.alerts_today || 0) + 1;
                if (alert.severity === 'CRITICAL') {
                    this.stats.telemetry.critical_alerts_today = (this.stats.telemetry.critical_alerts_today || 0) + 1;
                }
                const isUnresolved = alert.status !== 'RESOLVED' && alert.status !== 'DISMISSED';
                if (isUnresolved) {
                    this.stats.telemetry.unresolved_alerts = (this.stats.telemetry.unresolved_alerts || 0) + 1;
                }
            }

            if (this.soundEnabled || alert.severity === 'CRITICAL' || alert.severity === 'HIGH') {
                this.playAlertSound();
            }
        },

        updateAlertStatus(rawAlert) {
            const alert = normalizePayload(rawAlert);
            if (!alert || !alert.id) return;

            if (!this.stats.telemetry) {
                this.stats.telemetry = {
                    total_scans_today: 0,
                    allowed_today: 0,
                    rejected_today: 0,
                    strangers_today: 0,
                    alerts_today: 0,
                    critical_alerts_today: 0,
                    unresolved_alerts: 0,
                };
            }

            const index = this.deviceAlerts.findIndex(a => String(a.id) === String(alert.id));
            let prevStatus = alert.previous_status || null;

            if (index !== -1) {
                if (!prevStatus) {
                    prevStatus = this.deviceAlerts[index].status;
                }
                this.deviceAlerts[index] = {
                    ...this.deviceAlerts[index],
                    ...alert,
                };
            }

            const newStatus = alert.status;
            const wasUnresolved = !prevStatus || (prevStatus === 'NEW' || prevStatus === 'ACKNOWLEDGED');
            const isNowResolved = newStatus === 'RESOLVED' || newStatus === 'DISMISSED';
            const wasResolved = prevStatus === 'RESOLVED' || prevStatus === 'DISMISSED';
            const isNowUnresolved = newStatus === 'NEW' || newStatus === 'ACKNOWLEDGED';

            if (wasUnresolved && isNowResolved) {
                this.stats.telemetry.unresolved_alerts = Math.max(0, (this.stats.telemetry.unresolved_alerts || 0) - 1);
            } else if (wasResolved && isNowUnresolved) {
                this.stats.telemetry.unresolved_alerts = (this.stats.telemetry.unresolved_alerts || 0) + 1;
            }
        },

        updateDeviceStatus(rawDeviceData) {
            const deviceData = normalizePayload(rawDeviceData);
            if (!deviceData || !deviceData.device_id) return;

            if (!this.stats.devices) {
                this.stats.devices = { total: 0, online: 0, offline: 0 };
            }

            const index = this.devices.findIndex(d => String(d.device_id) === String(deviceData.device_id));
            let prevOnline = false;
            let isNewDevice = false;

            if (index !== -1) {
                const existing = this.devices[index];
                prevOnline = Boolean(existing.is_online) && (existing.is_active !== false);
                this.devices[index] = {
                    ...existing,
                    ...deviceData,
                    access_logs_count: deviceData.access_logs_count !== undefined && deviceData.access_logs_count !== null 
                        ? deviceData.access_logs_count 
                        : (existing.access_logs_count || 0),
                    stranger_snaps_count: deviceData.stranger_snaps_count !== undefined && deviceData.stranger_snaps_count !== null 
                        ? deviceData.stranger_snaps_count 
                        : (existing.stranger_snaps_count || 0),
                };
            } else {
                isNewDevice = true;
                this.devices.unshift(deviceData);
            }

            // Recalculate device counts purely in memory without polling HTTP
            if (this.devices.length >= this.stats.devices.total) {
                const total = this.devices.length;
                const online = this.devices.filter(d => Boolean(d.is_online) && (d.is_active !== false)).length;
                this.stats.devices.total = total;
                this.stats.devices.online = online;
                this.stats.devices.offline = Math.max(0, total - online);
            } else {
                const currOnline = Boolean(deviceData.is_online) && (deviceData.is_active !== false);
                if (isNewDevice) {
                    this.stats.devices.total = (this.stats.devices.total || 0) + 1;
                    if (currOnline) {
                        this.stats.devices.online = (this.stats.devices.online || 0) + 1;
                    } else {
                        this.stats.devices.offline = (this.stats.devices.offline || 0) + 1;
                    }
                } else if (prevOnline !== currOnline) {
                    if (currOnline) {
                        this.stats.devices.online = (this.stats.devices.online || 0) + 1;
                        this.stats.devices.offline = Math.max(0, (this.stats.devices.offline || 0) - 1);
                    } else {
                        this.stats.devices.online = Math.max(0, (this.stats.devices.online || 0) - 1);
                        this.stats.devices.offline = (this.stats.devices.offline || 0) + 1;
                    }
                }
            }
        },

        handlePersonnelUpdated(rawPayload) {
            const payload = normalizePayload(rawPayload);
            if (!payload || !payload.action) return;

            if (!this.stats.personnel) {
                this.stats.personnel = { total: 0, whitelisted: 0, blacklisted: 0 };
            }

            const action = payload.action;
            const personType = Number(payload.person_type);
            const oldPersonType = payload.old_person_type !== undefined && payload.old_person_type !== null 
                ? Number(payload.old_person_type) 
                : null;

            if (action === 'created') {
                this.stats.personnel.total = (this.stats.personnel.total || 0) + 1;
                if (personType === 0) {
                    this.stats.personnel.whitelisted = (this.stats.personnel.whitelisted || 0) + 1;
                } else {
                    this.stats.personnel.blacklisted = (this.stats.personnel.blacklisted || 0) + 1;
                }
            } else if (action === 'deleted') {
                this.stats.personnel.total = Math.max(0, (this.stats.personnel.total || 0) - 1);
                if (personType === 0) {
                    this.stats.personnel.whitelisted = Math.max(0, (this.stats.personnel.whitelisted || 0) - 1);
                } else {
                    this.stats.personnel.blacklisted = Math.max(0, (this.stats.personnel.blacklisted || 0) - 1);
                }
            } else if (action === 'updated' && oldPersonType !== null && oldPersonType !== personType) {
                if (personType === 0) {
                    this.stats.personnel.whitelisted = (this.stats.personnel.whitelisted || 0) + 1;
                    this.stats.personnel.blacklisted = Math.max(0, (this.stats.personnel.blacklisted || 0) - 1);
                } else {
                    this.stats.personnel.blacklisted = (this.stats.personnel.blacklisted || 0) + 1;
                    this.stats.personnel.whitelisted = Math.max(0, (this.stats.personnel.whitelisted || 0) - 1);
                }
            }
        },

        handleSyncTaskUpdated(rawPayload) {
            const payload = normalizePayload(rawPayload);
            if (!payload || !payload.status) return;

            if (!this.stats.sync) {
                this.stats.sync = { pending: 0, failed: 0 };
            }

            const status = payload.status;
            const oldStatus = payload.old_status || null;

            if (!oldStatus) {
                if (status === 'PENDING' || status === 'PROCESSING') {
                    this.stats.sync.pending = (this.stats.sync.pending || 0) + 1;
                } else if (status === 'FAILED') {
                    this.stats.sync.failed = (this.stats.sync.failed || 0) + 1;
                }
            } else if (oldStatus !== status) {
                const wasPending = oldStatus === 'PENDING' || oldStatus === 'PROCESSING';
                const isPending = status === 'PENDING' || status === 'PROCESSING';
                const wasFailed = oldStatus === 'FAILED';
                const isFailed = status === 'FAILED';

                if (wasPending && !isPending) {
                    this.stats.sync.pending = Math.max(0, (this.stats.sync.pending || 0) - 1);
                } else if (!wasPending && isPending) {
                    this.stats.sync.pending = (this.stats.sync.pending || 0) + 1;
                }

                if (wasFailed && !isFailed) {
                    this.stats.sync.failed = Math.max(0, (this.stats.sync.failed || 0) - 1);
                } else if (!wasFailed && isFailed) {
                    this.stats.sync.failed = (this.stats.sync.failed || 0) + 1;
                }
            }
        },

        playAlertSound() {
            try {
                if (!audioContextSingleton) {
                    audioContextSingleton = new (window.AudioContext || window.webkitAudioContext)();
                }
                const ctx = audioContextSingleton;
                if (ctx.state === 'suspended') {
                    ctx.resume();
                }
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sawtooth';
                osc.frequency.setValueAtTime(440, ctx.currentTime);
                osc.frequency.exponentialRampToValueAtTime(220, ctx.currentTime + 0.3);
                gain.gain.setValueAtTime(0.2, ctx.currentTime);
                gain.gain.linearRampToValueAtTime(0.01, ctx.currentTime + 0.3);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start();
                osc.stop(ctx.currentTime + 0.3);
            } catch (e) {
                // Ignore audio errors
            }
        }
    }
});
