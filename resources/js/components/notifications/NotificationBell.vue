<template>
    <div class="relative">
        <button 
            @click="showDropdown = !showDropdown" 
            aria-haspopup="dialog"
            :aria-expanded="showDropdown"
            :aria-label="notificationStore.unreadCount > 0 ? `${notificationStore.unreadCount} unread notifications` : 'Notifications'"
            @keydown.escape="showDropdown = false"
            class="relative p-2 text-slate-500 hover:text-slate-800 rounded-xl hover:bg-slate-100 transition-colors border border-transparent hover:border-slate-200 cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
            title="Notifications"
        >
            <span class="text-lg" aria-hidden="true">🔔</span>
            <span 
                v-if="notificationStore.unreadCount > 0"
                class="absolute top-1 right-1 min-w-4 h-4 px-1 bg-rose-600 text-white font-bold text-[10px] rounded-full flex items-center justify-center animate-bounce motion-reduce:animate-none shadow-xs"
            >
                {{ notificationStore.unreadCount > 9 ? '9+' : notificationStore.unreadCount }}
            </span>
        </button>

        <!-- Dropdown Dialog Panel -->
        <div 
            v-if="showDropdown" 
            role="dialog"
            aria-label="Notifications Panel"
            aria-modal="false"
            @keydown.escape="showDropdown = false"
            class="absolute right-0 mt-2 w-80 sm:w-96 bg-white border border-slate-200 rounded-2xl shadow-xl z-50 p-4 space-y-3 divide-y divide-slate-100 animate-in fade-in zoom-in-95 duration-150"
        >
            <div class="flex items-center justify-between pb-2">
                <div class="flex items-center gap-2">
                    <span class="text-sm" aria-hidden="true">🔔</span>
                    <span class="font-bold text-slate-900 text-sm">Notifications</span>
                    <span v-if="notificationStore.unreadCount > 0" class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-rose-50 text-rose-700 border border-rose-200">
                        {{ notificationStore.unreadCount }} new
                    </span>
                </div>
                <div class="flex items-center gap-2">
                    <button 
                        @click="notificationStore.markAllAsRead" 
                        class="text-[11px] font-semibold text-indigo-600 hover:text-indigo-800 cursor-pointer focus:underline focus:outline-none"
                    >
                        Mark all read
                    </button>
                    <button 
                        @click="showDropdown = false" 
                        aria-label="Close notifications panel"
                        class="text-slate-400 hover:text-slate-700 text-xs font-bold cursor-pointer p-1 rounded hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    >✕</button>
                </div>
            </div>

            <div v-if="notificationStore.notifications.length === 0" class="py-8 text-center text-slate-500 text-xs">
                No notifications to display.
            </div>
            <div v-else class="pt-2 space-y-2 max-h-80 overflow-y-auto pr-1" role="feed" aria-label="Notifications list">
                <!-- Semantic <button> item replacing clickable <div> -->
                <button 
                    v-for="n in notificationStore.notifications" 
                    :key="n.id"
                    type="button"
                    @click="markRead(n)"
                    :aria-label="`${n.read_at ? 'Read' : 'Unread'} notification: ${n.title || 'System Notification'}`"
                    :class="n.read_at ? 'bg-slate-50 text-slate-600 border border-slate-200/60' : 'bg-indigo-50/70 text-slate-900 font-medium border-l-3 border-indigo-600 border border-indigo-100 shadow-xs'"
                    class="w-full text-left p-3 rounded-xl cursor-pointer hover:bg-slate-100/80 transition-colors space-y-1 block focus:outline-none focus:ring-2 focus:ring-indigo-500"
                >
                    <div class="flex justify-between items-start gap-2">
                        <div class="text-xs font-bold text-slate-900">{{ n.title || 'System Notification' }}</div>
                        <span class="text-[10px] text-slate-400 font-mono whitespace-nowrap">{{ formatTime(n.created_at) }}</span>
                    </div>
                    <div class="text-[11px] text-slate-600 leading-relaxed">{{ n.message || n.data?.message || '' }}</div>
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useNotificationStore } from '../../stores/notificationStore';

const notificationStore = useNotificationStore();
const showDropdown = ref(false);

onMounted(() => {
    notificationStore.fetchNotifications();
});

const markRead = (n) => {
    if (!n.read_at) {
        notificationStore.markAsRead(n.id);
    }
};

const formatTime = (ts) => {
    if (!ts) return '';
    try {
        const d = new Date(ts);
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    } catch {
        return '';
    }
};
</script>
