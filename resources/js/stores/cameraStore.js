import { defineStore } from 'pinia';
import axios from 'axios';

export const useCameraStore = defineStore('camera', {
    state: () => ({
        liveLogs: [],
        strangerSnaps: [],
        devices: [],
        stats: {
            telemetry: {
                total_scans_today: 0,
                allowed_today: 0,
                rejected_today: 0,
                strangers_today: 0,
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
    }),

    actions: {
        async fetchStats() {
            try {
                const res = await axios.get('/api/stats');
                this.stats = res.data;
            } catch (err) {
                console.error('Failed to fetch stats:', err);
            }
        },

        async fetchDevices() {
            try {
                const res = await axios.get('/api/devices');
                this.devices = res.data;
            } catch (err) {
                console.error('Failed to fetch devices:', err);
            }
        },

        async fetchRecentLogs() {
            try {
                const res = await axios.get('/api/access-logs?per_page=25');
                this.liveLogs = res.data.data || [];
            } catch (err) {
                console.error('Failed to fetch access logs:', err);
            }
        },

        addLiveLog(log) {
            this.liveLogs.unshift(log);
            if (this.liveLogs.length > 50) {
                this.liveLogs.pop();
            }

            if (this.stats.telemetry) {
                this.stats.telemetry.total_scans_today++;
                if (log.verify_status === 1) {
                    this.stats.telemetry.allowed_today++;
                } else {
                    this.stats.telemetry.rejected_today++;
                }
            }

            if (this.soundEnabled && log.verify_status === 2) {
                this.playAlertSound();
            }
        },

        addStrangerSnap(snap) {
            this.strangerSnaps.unshift(snap);
            if (this.strangerSnaps.length > 30) {
                this.strangerSnaps.pop();
            }

            if (this.stats.telemetry) {
                this.stats.telemetry.strangers_today++;
            }
        },

        updateDeviceStatus(deviceData) {
            const index = this.devices.findIndex(d => d.device_id === deviceData.device_id);
            if (index !== -1) {
                this.devices[index] = { ...this.devices[index], ...deviceData };
            } else {
                this.devices.unshift(deviceData);
            }
            this.fetchStats();
        },

        playAlertSound() {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
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
