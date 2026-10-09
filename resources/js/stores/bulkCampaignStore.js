import { defineStore } from 'pinia';
import bulkCampaignApi from '../api/bulkCampaigns';
import notify from '../utils/notify';

export const useBulkCampaignStore = defineStore('bulkCampaign', {
    state: () => ({
        activeCampaign: null,
        activeCampaignId: null,
        isPolling: false,
        pollIntervalId: null,
        errorCount: 0,
        modalVisible: false,
        modalTitle: '',
    }),

    getters: {
        progressPercent: (state) => {
            if (!state.activeCampaign || !state.activeCampaign.total_items) return 0;
            const total = state.activeCampaign.total_items;
            const processed = state.activeCampaign.processed_items || 0;
            return Math.min(100, Math.max(0, Math.round((processed / total) * 100)));
        },
        isCompleted: (state) => state.activeCampaign?.status === 'completed',
        isFailed: (state) => state.activeCampaign?.status === 'failed',
        isFinished: (state) => state.activeCampaign?.status === 'completed' || state.activeCampaign?.status === 'failed',
    },

    actions: {
        async startFleetReboot(deviceIds) {
            const res = await bulkCampaignApi.rebootDevices(deviceIds);
            const campaignId = res.data?.campaign_id || res.data?.data?.campaign_id;
            if (campaignId) {
                this.trackCampaign(campaignId, 'Fleet Reboot Campaign');
            }
            return res.data;
        },

        async startFleetMqttSync(deviceIds, mqttConfig) {
            const res = await bulkCampaignApi.syncMqttConfig(deviceIds, mqttConfig);
            const campaignId = res.data?.campaign_id || res.data?.data?.campaign_id;
            if (campaignId) {
                this.trackCampaign(campaignId, 'Fleet MQTT Parameter Update');
            }
            return res.data;
        },

        async startPersonnelSync(personnelIds) {
            const res = await bulkCampaignApi.syncPersonnel(personnelIds);
            const campaignId = res.data?.campaign_id || res.data?.data?.campaign_id;
            if (campaignId) {
                this.trackCampaign(campaignId, 'Personnel Biometric Camera Sync');
            }
            return res.data;
        },

        async startPersonnelDelete(personnelIds) {
            const res = await bulkCampaignApi.deletePersonnel(personnelIds);
            const campaignId = res.data?.campaign_id || res.data?.data?.campaign_id;
            if (campaignId) {
                this.trackCampaign(campaignId, 'Personnel Bulk Deletion');
            }
            return res.data;
        },

        trackCampaign(campaignId, title = 'Campaign Execution', onComplete = null) {
            this.stopPolling();
            this.activeCampaignId = campaignId;
            this.modalTitle = title;
            this.modalVisible = true;
            this.isPolling = true;
            this.errorCount = 0;

            const poll = async () => {
                try {
                    const res = await bulkCampaignApi.getCampaign(this.activeCampaignId);
                    this.activeCampaign = res.data?.data || res.data;
                    this.errorCount = 0;

                    if (this.isFinished) {
                        this.stopPolling();
                        if (onComplete) onComplete(this.activeCampaign);
                    }
                } catch (err) {
                    this.errorCount++;
                    if (this.errorCount >= 5) {
                        this.stopPolling();
                        notify.error('Polling Terminated', 'Could not retrieve bulk campaign progress.');
                    }
                }
            };

            poll();
            this.pollIntervalId = setInterval(poll, 1200);
        },

        stopPolling() {
            if (this.pollIntervalId) {
                clearInterval(this.pollIntervalId);
                this.pollIntervalId = null;
            }
            this.isPolling = false;
        },

        closeModal() {
            this.modalVisible = false;
        },

        clearCampaign() {
            this.stopPolling();
            this.activeCampaign = null;
            this.activeCampaignId = null;
            this.modalVisible = false;
        },
    },
});

export default useBulkCampaignStore;
