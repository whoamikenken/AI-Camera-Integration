import { defineStore } from 'pinia';
import apiClient from '../api/client';
import notify from '../utils/notify';

export const useNotificationStore = defineStore('notification', {
    state: () => ({
        notifications: [],
        unreadCount: 0,
        loading: false,
    }),

    actions: {
        async fetchNotifications() {
            this.loading = true;
            try {
                const res = await apiClient.get('/notifications');
                this.notifications = res.data.data || [];
                this.unreadCount = res.data.unread_count || 0;
            } catch (err) {
                console.error('Failed to load notifications:', err);
            } finally {
                this.loading = false;
            }
        },

        async markAsRead(id) {
            try {
                await apiClient.put(`/notifications/${id}/read`);
                const item = this.notifications.find(n => n.id === id);
                if (item && !item.read_at) {
                    item.read_at = new Date().toISOString();
                    this.unreadCount = Math.max(0, this.unreadCount - 1);
                }
            } catch (err) {
                console.error('Failed to mark notification as read:', err);
            }
        },

        async markAllAsRead() {
            try {
                await apiClient.put('/notifications/mark-all-read');
                this.notifications.forEach(n => { n.read_at = new Date().toISOString(); });
                this.unreadCount = 0;
                notify.toast('All notifications marked as read.', 'info');
            } catch (err) {
                console.error('Failed to mark all notifications as read:', err);
            }
        },

        handleLiveNotification(notif) {
            if (!notif) return;
            this.notifications.unshift(notif);
            this.unreadCount++;
            notify.toast(notif.title || 'New Notification', 'info');
        },
    },
});
