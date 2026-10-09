import apiClient from './client';

export const bulkCampaignApi = {
    /**
     * Dispatch fleet bulk reboot command
     * @param {number[]} deviceIds
     * @returns {Promise<{success: boolean, campaign_id: number, message: string}>}
     */
    rebootDevices(deviceIds) {
        return apiClient.post('/devices/bulk-reboot', { device_ids: deviceIds });
    },

    /**
     * Dispatch fleet bulk MQTT configuration update
     * @param {number[]} deviceIds
     * @param {object} mqttConfig
     * @returns {Promise<{success: boolean, campaign_id: number, message: string}>}
     */
    syncMqttConfig(deviceIds, mqttConfig) {
        return apiClient.post('/devices/bulk-sync-mqtt', {
            device_ids: deviceIds,
            mqtt_config: mqttConfig,
        });
    },

    /**
     * Dispatch bulk personnel synchronization to cameras (AddPersons batch up to 50)
     * @param {number[]} personnelIds
     * @returns {Promise<{success: boolean, campaign_id: number, message: string}>}
     */
    syncPersonnel(personnelIds) {
        return apiClient.post('/personnel/bulk-sync', { personnel_ids: personnelIds });
    },

    /**
     * Dispatch bulk personnel deletion from database and edge cameras
     * @param {number[]} personnelIds
     * @returns {Promise<{success: boolean, campaign_id: number, message: string}>}
     */
    deletePersonnel(personnelIds) {
        return apiClient.post('/personnel/bulk-delete', { personnel_ids: personnelIds });
    },

    /**
     * Retrieve status and execution counters of a bulk campaign
     * @param {number|string} campaignId
     * @returns {Promise<{id: number, campaign_type: string, total_items: number, processed_items: number, failed_items: number, status: string}>}
     */
    getCampaign(campaignId) {
        return apiClient.get(`/bulk-campaigns/${campaignId}`);
    },
};

export default bulkCampaignApi;
