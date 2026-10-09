<template>
    <!-- AUTHENTICATION GATE: RENDER LOGIN PAGE IF UNAUTHENTICATED -->
    <div v-if="!authStore.isAuthenticated" class="min-h-screen">
        <LoginPage />
    </div>

    <!-- AUTHENTICATED SYSTEM SHELL: SIDENAV + MAIN CONTENT WORKSPACE -->
    <div
        v-else
        class="min-h-screen bg-slate-50 text-slate-900 flex font-sans antialiased selection:bg-indigo-500 selection:text-white"
    >
        <!-- MOBILE BACKDROP OVERLAY -->
        <div
            v-if="mobileMenuOpen"
            @click="mobileMenuOpen = false"
            class="fixed inset-0 z-40 bg-slate-900/50 backdrop-blur-xs lg:hidden transition-opacity"
        ></div>

        <!-- SIDEBAR NAVIGATION (SIDENAV) -->
        <aside
            id="sidebar-nav"
            aria-label="Main Navigation"
            class="fixed inset-y-0 left-0 z-50 w-72 bg-white border-r border-slate-200/80 flex flex-col justify-between transition-transform duration-300 ease-in-out lg:static lg:translate-x-0 shadow-sm lg:shadow-none"
            :class="mobileMenuOpen ? 'translate-x-0' : '-translate-x-full'"
        >
            <!-- Sidenav Top: Brand & Identity -->
            <div
                class="p-5 border-b border-slate-100 flex items-center justify-between"
            >
                <div class="flex items-center gap-3">
                    <img
                        :src="logoLight"
                        alt="Pinnacle Technologies Logo"
                        class="h-9 w-auto"
                    />
                    <div>
                        <div class="flex items-center gap-1.5">
                            <h1
                                class="text-sm font-bold tracking-tight text-slate-900"
                            >
                                AI Camera Hub
                            </h1>
                            <span
                                class="px-1.5 py-0.5 rounded text-[9px] font-extrabold bg-indigo-50 text-indigo-700 border border-indigo-200 uppercase font-mono"
                                >v4.2</span
                            >
                        </div>
                        <p class="text-[10px] text-slate-400 font-medium">
                            Biometric Vision &amp; Attendance
                        </p>
                    </div>
                </div>
                <!-- Close button on mobile -->
                <button
                    @click="mobileMenuOpen = false"
                    aria-label="Close navigation menu"
                    class="lg:hidden text-slate-400 hover:text-slate-700 p-1.5 rounded-lg hover:bg-slate-100 cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
                >
                    <span aria-hidden="true">✕</span>
                </button>
            </div>

            <!-- Sidenav Menu Navigation Links (Grouped & Scrollable) -->
            <nav class="flex-1 overflow-y-auto px-3.5 py-4 space-y-6">
                <div
                    v-for="group in navGroups"
                    :key="group.title"
                    class="space-y-1"
                >
                    <div
                        class="px-3 text-[10px] font-bold tracking-wider text-slate-400 uppercase"
                    >
                        {{ group.title }}
                    </div>

                    <div class="space-y-0.5 pt-1">
                        <button
                            v-for="item in group.items"
                            :key="item.id"
                            @click="switchTab(item.id)"
                            class="w-full flex items-center justify-between px-3 py-2 rounded-xl text-xs font-semibold transition-all cursor-pointer group"
                            :class="
                                currentTab === item.id
                                    ? 'bg-indigo-600 text-white shadow-xs font-bold'
                                    : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100/80'
                            "
                        >
                            <div class="flex items-center gap-2.5">
                                <span
                                    class="text-sm transition-transform group-hover:scale-110"
                                    >{{ item.icon }}</span
                                >
                                <span>{{ item.label }}</span>
                            </div>

                            <!-- Badges / Indicators -->
                            <span
                                v-if="
                                    item.id === 'sync' &&
                                    store.stats.sync?.pending > 0
                                "
                                class="px-1.5 py-0.5 rounded-full text-[10px] font-mono font-bold"
                                :class="
                                    currentTab === item.id
                                        ? 'bg-white/20 text-white'
                                        : 'bg-amber-100 text-amber-800'
                                "
                            >
                                {{ store.stats.sync?.pending }}
                            </span>
                            <span
                                v-else-if="
                                    item.id === 'strangers' &&
                                    store.stats.telemetry?.strangers_today > 0
                                "
                                class="px-1.5 py-0.5 rounded-full text-[10px] font-mono font-bold"
                                :class="
                                    currentTab === item.id
                                        ? 'bg-white/20 text-white'
                                        : 'bg-amber-100 text-amber-800'
                                "
                            >
                                {{ store.stats.telemetry?.strangers_today }}
                            </span>
                            <span
                                v-else-if="
                                    item.id === 'alerts' &&
                                    store.stats.telemetry?.unresolved_alerts > 0
                                "
                                class="px-1.5 py-0.5 rounded-full text-[10px] font-mono font-bold animate-pulse motion-reduce:animate-none"
                                :class="
                                    currentTab === item.id
                                        ? 'bg-white/20 text-white'
                                        : 'bg-rose-100 text-rose-800 border border-rose-200'
                                "
                            >
                                {{ store.stats.telemetry?.unresolved_alerts }}
                            </span>
                        </button>
                    </div>
                </div>
            </nav>

            <!-- Sidenav Bottom: User Identity & Sign Out -->
            <div class="p-3.5 border-t border-slate-100 bg-slate-50/50">
                <div
                    class="flex items-center justify-between p-2 rounded-xl bg-white border border-slate-200/80 shadow-2xs"
                >
                    <div class="flex items-center gap-2.5 min-w-0">
                        <div
                            class="w-8 h-8 rounded-full bg-indigo-600 text-white font-bold text-xs flex items-center justify-center shrink-0 shadow-2xs"
                        >
                            {{ authStore.userInitials }}
                        </div>
                        <div class="min-w-0 text-left">
                            <div
                                class="text-xs font-bold text-slate-900 truncate leading-tight"
                            >
                                {{ authStore.userName }}
                            </div>
                            <div
                                class="text-[10px] text-indigo-600 font-semibold uppercase truncate"
                            >
                                {{ authStore.primaryRoleBadge }}
                            </div>
                        </div>
                    </div>

                    <button
                        @click="handleLogout"
                        class="p-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition-colors cursor-pointer shrink-0"
                        title="Sign Out"
                    >
                        <span class="text-sm">🚪</span>
                    </button>
                </div>
            </div>
        </aside>

        <!-- MAIN WORKSPACE CONTENT AREA -->
        <div class="flex-1 flex flex-col min-w-0 h-screen overflow-y-auto">
            <!-- Top Sticky App Bar -->
            <header
                class="bg-white/90 border-b border-slate-200/80 sticky top-0 z-30 backdrop-blur-md px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between shadow-2xs"
            >
                <div class="flex items-center gap-3">
                    <!-- Mobile Menu Trigger -->
                    <button
                        @click="mobileMenuOpen = true"
                        :aria-expanded="mobileMenuOpen"
                        aria-controls="sidebar-nav"
                        aria-label="Open navigation menu"
                        class="lg:hidden p-2 rounded-xl text-slate-600 hover:text-slate-900 hover:bg-slate-100 border border-slate-200 cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
                        title="Open Menu"
                    >
                        <span class="text-sm" aria-hidden="true">☰</span>
                    </button>

                    <!-- Current View Title & Breadcrumb -->
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="text-base" aria-hidden="true">{{
                                currentTabMeta.icon
                            }}</span>
                            <h2
                                class="text-sm sm:text-base font-bold text-slate-900 tracking-tight"
                            >
                                {{ currentTabMeta.label }}
                            </h2>
                        </div>
                        <p class="text-[11px] text-slate-500 hidden sm:block">
                            {{ currentTabMeta.description }}
                        </p>
                    </div>
                </div>

                <!-- Header Right: Reverb Status, Notifications & Profile -->
                <div class="flex items-center gap-3">
                    <!-- Notification Bell -->
                    <NotificationBell />

                    <!-- Quick User Menu Dropdown -->
                    <div class="relative" ref="userMenuRef" :key="'user-menu-dropdown'">
                        <button
                            @click="userMenuOpen = !userMenuOpen"
                            aria-haspopup="menu"
                            :aria-expanded="userMenuOpen"
                            aria-label="User profile menu"
                            @keydown.escape="userMenuOpen = false"
                            class="flex items-center gap-2 p-1.5 rounded-xl hover:bg-slate-100 transition-colors cursor-pointer border border-transparent hover:border-slate-200 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                        >
                            <div
                                class="w-7 h-7 rounded-full bg-indigo-600 text-white font-bold text-xs flex items-center justify-center shadow-2xs"
                            >
                                {{ authStore.userInitials }}
                            </div>
                            <span
                                class="text-slate-400 text-xs hidden md:inline"
                                aria-hidden="true"
                                >▾</span
                            >
                        </button>

                        <!-- Dropdown Menu -->
                        <div
                            v-if="userMenuOpen"
                            role="menu"
                            aria-orientation="vertical"
                            aria-label="User account actions"
                            @keydown.escape="userMenuOpen = false"
                            class="absolute right-0 mt-2 w-56 bg-white border border-slate-200 rounded-2xl shadow-xl py-2 z-50 divide-y divide-slate-100 animate-in fade-in zoom-in-95 duration-150"
                        >
                            <div class="px-4 py-2 text-xs" role="none">
                                <div class="font-bold text-slate-900">
                                    {{ authStore.userName }}
                                </div>
                                <div
                                    class="text-[11px] text-slate-500 font-mono truncate"
                                >
                                    {{ authStore.userEmail }}
                                </div>
                                <div class="mt-1 flex flex-wrap gap-1">
                                    <span
                                        v-for="role in authStore.roles"
                                        :key="role"
                                        class="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase bg-slate-100 text-slate-600"
                                    >
                                        {{ role }}
                                    </span>
                                </div>
                            </div>

                            <div class="py-1 text-xs" role="none">
                                <button
                                    v-if="authStore.isAdmin"
                                    role="menuitem"
                                    tabindex="0"
                                    @click="switchTab('settings'); userMenuOpen = false"
                                    class="w-full text-left px-4 py-2 hover:bg-slate-50 text-slate-700 flex items-center gap-2 cursor-pointer focus:bg-slate-50 focus:outline-none"
                                >
                                    <span aria-hidden="true">⚙️</span> System Settings
                                </button>
                            </div>

                            <div class="py-1 text-xs" role="none">
                                <button
                                    role="menuitem"
                                    tabindex="0"
                                    @click="handleLogout"
                                    class="w-full text-left px-4 py-2 hover:bg-rose-50 text-rose-600 font-semibold flex items-center gap-2 cursor-pointer focus:bg-rose-50 focus:outline-none"
                                >
                                    <span aria-hidden="true">🚪</span> Sign Out
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- KPI Summary Metric Cards -->
            <section class="px-4 sm:px-6 lg:px-8 pt-6">
                <!-- Skeleton loader when store stats are fetching -->
                <div
                    v-if="store.statsLoading"
                    class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3"
                    aria-label="Loading metric summaries"
                    aria-busy="true"
                >
                    <div
                        v-for="i in 6"
                        :key="i"
                        class="bg-white border border-slate-200/80 rounded-2xl p-4 shadow-xs animate-pulse motion-reduce:animate-none space-y-2"
                    >
                        <div class="h-3 bg-slate-200 rounded w-20"></div>
                        <div class="h-7 bg-slate-200 rounded w-14"></div>
                        <div class="h-2.5 bg-slate-100 rounded w-24"></div>
                    </div>
                </div>

                <div
                    v-else
                    class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3"
                >
                    <!-- Metric 1: Total Verifications Today -->
                    <div
                        class="bg-white border border-slate-200/80 rounded-2xl p-4 shadow-xs"
                    >
                        <div
                            class="text-[11px] font-bold text-slate-500 uppercase tracking-wider"
                        >
                            Scans Today
                        </div>
                        <div
                            class="text-2xl font-bold text-slate-900 mt-1 font-mono"
                        >
                            {{ store.stats.telemetry?.total_scans_today || 0 }}
                        </div>
                        <div
                            class="text-[10px] text-emerald-600 font-medium mt-0.5"
                        >
                            {{
                                store.stats.telemetry?.allowed_today || 0
                            }}
                            allowed passes
                        </div>
                    </div>

                    <!-- Metric 2: Unregistered Strangers -->
                    <div
                        class="bg-white border border-slate-200/80 rounded-2xl p-4 shadow-xs"
                    >
                        <div
                            class="text-[11px] font-bold text-slate-500 uppercase tracking-wider"
                        >
                            Strangers Today
                        </div>
                        <div
                            class="text-2xl font-bold text-amber-600 mt-1 font-mono"
                        >
                            {{ store.stats.telemetry?.strangers_today || 0 }}
                        </div>
                        <div
                            class="text-[10px] text-amber-700 font-medium mt-0.5"
                        >
                            Unregistered face captures
                        </div>
                    </div>

                    <!-- Metric 3: AI Safety & Security Alerts -->
                    <div
                        class="bg-white border border-slate-200/80 rounded-2xl p-4 shadow-xs"
                        :class="
                            store.stats.telemetry?.unresolved_alerts > 0
                                ? 'border-rose-300 bg-rose-50/30'
                                : ''
                        "
                    >
                        <div
                            class="text-[11px] font-bold uppercase tracking-wider flex items-center justify-between"
                            :class="
                                store.stats.telemetry?.unresolved_alerts > 0
                                    ? 'text-rose-700'
                                    : 'text-slate-500'
                            "
                        >
                            <span>AI Alerts</span>
                            <span
                                v-if="
                                    store.stats.telemetry?.unresolved_alerts > 0
                                "
                                class="w-2 h-2 rounded-full bg-rose-500 animate-ping motion-reduce:animate-none"
                            ></span>
                        </div>
                        <div
                            class="text-2xl font-bold mt-1 font-mono"
                            :class="
                                store.stats.telemetry?.unresolved_alerts > 0
                                    ? 'text-rose-600'
                                    : 'text-slate-900'
                            "
                        >
                            {{ store.stats.telemetry?.alerts_today || 0 }}
                        </div>
                        <div
                            class="text-[10px] font-medium mt-0.5"
                            :class="
                                store.stats.telemetry?.unresolved_alerts > 0
                                    ? 'text-rose-700 font-bold'
                                    : 'text-slate-500'
                            "
                        >
                            {{
                                store.stats.telemetry?.unresolved_alerts || 0
                            }}
                            unresolved
                        </div>
                    </div>

                    <!-- Metric 4: Online Camera Fleet -->
                    <div
                        class="bg-white border border-slate-200/80 rounded-2xl p-4 shadow-xs"
                    >
                        <div
                            class="text-[11px] font-bold text-slate-500 uppercase tracking-wider"
                        >
                            Active Cameras
                        </div>
                        <div
                            class="text-2xl font-bold text-emerald-600 mt-1 font-mono"
                        >
                            {{ store.stats.devices?.online || 0 }} /
                            {{ store.stats.devices?.total || 0 }}
                        </div>
                        <div
                            class="text-[10px] text-slate-500 mt-0.5 font-medium"
                        >
                            {{ store.stats.devices?.offline || 0 }} offline
                        </div>
                    </div>

                    <!-- Metric 5: Personnel Face Library -->
                    <div
                        class="bg-white border border-slate-200/80 rounded-2xl p-4 shadow-xs"
                    >
                        <div
                            class="text-[11px] font-bold text-slate-500 uppercase tracking-wider"
                        >
                            Face Library
                        </div>
                        <div
                            class="text-2xl font-bold text-indigo-600 mt-1 font-mono"
                        >
                            {{ store.stats.personnel?.total || 0 }}
                        </div>
                        <div
                            class="text-[10px] text-slate-500 mt-0.5 font-medium"
                        >
                            {{ store.stats.personnel?.whitelisted || 0 }} allow
                            /
                            {{ store.stats.personnel?.blacklisted || 0 }} block
                        </div>
                    </div>

                    <!-- Metric 6: Redis Outbox Queue -->
                    <div
                        class="bg-white border border-slate-200/80 rounded-2xl p-4 shadow-xs"
                    >
                        <div
                            class="text-[11px] font-bold text-slate-500 uppercase tracking-wider"
                        >
                            Sync Outbox
                        </div>
                        <div
                            class="text-2xl font-bold mt-1 font-mono"
                            :class="
                                store.stats.sync?.failed > 0
                                    ? 'text-rose-600'
                                    : 'text-slate-900'
                            "
                        >
                            {{ store.stats.sync?.pending || 0 }}
                        </div>
                        <div
                            class="text-[10px]"
                            :class="
                                store.stats.sync?.failed > 0
                                    ? 'text-rose-600 font-bold'
                                    : 'text-slate-500 font-medium'
                            "
                        >
                            {{ store.stats.sync?.failed || 0 }} failed tasks
                        </div>
                    </div>
                </div>
            </section>

            <!-- Dynamic Active View Workspace -->
            <main class="px-4 sm:px-6 lg:px-8 py-6 flex-1 flex flex-col">
                <div class="flex-1">
                    <LiveTelemetry v-if="currentTab === 'live'" />
                    <StrangerSnapsMonitor
                        v-else-if="currentTab === 'strangers'"
                    />
                    <DeviceAlertsCenter v-else-if="currentTab === 'alerts'" />
                    <EmployeeDirectory v-else-if="currentTab === 'employees'" />
                    <ScheduleHub v-else-if="currentTab === 'schedules'" />
                    <AttendanceHub v-else-if="currentTab === 'attendance'" />
                    <LeaveHub v-else-if="currentTab === 'leave'" />
                    <VisitorHub v-else-if="currentTab === 'visitors'" />
                    <ReportsHub v-else-if="currentTab === 'reports'" />
                    <PersonnelManager v-else-if="currentTab === 'personnel'" />
                    <DeviceManager v-else-if="currentTab === 'devices'" />
                    <AccessLogsHistory v-else-if="currentTab === 'logs'" />
                    <SyncTasksMonitor v-else-if="currentTab === 'sync'" />
                    <SettingsHub v-else-if="currentTab === 'settings'" />
                </div>
            </main>

            <!-- Footer -->
            <footer
                class="bg-white border-t border-slate-200 py-4 text-center text-xs text-slate-500 mt-auto"
            >
                <div
                    class="px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-3"
                >
                    <div class="flex items-center gap-2">
                        <img
                            :src="logoIcon"
                            alt="Pinnacle Icon"
                            class="h-5 w-auto"
                        />
                        <span class="font-semibold text-slate-800"
                            >Pinnacle Technologies</span
                        >
                        &mdash;
                        <span
                            >Intelligent AI Camera Hub &amp; Biometric
                            Attendance System</span
                        >
                    </div>
                    <span class="text-[11px]">
                        MQTT Broker:
                        <code class="text-indigo-600 font-mono font-bold"
                            >:1883</code
                        >
                        &bull; Reverb WebSockets:
                        <code class="text-indigo-600 font-mono font-bold"
                            >:8080</code
                        >
                    </span>
                </div>
            </footer>
        </div>
    </div>
</template>

<script setup>
import {
    ref,
    computed,
    onMounted,
    onUnmounted,
    defineAsyncComponent,
    watch,
} from "vue";
import logoLight from "../images/pinnacle-logo-light.svg";
import logoIcon from "../images/pinnacle-icon.svg";
import { useCameraStore } from "./stores/cameraStore";
import { useAuthStore } from "./stores/authStore";
import { useAttendanceStore } from "./stores/attendanceStore";
import { useVisitorStore } from "./stores/visitorStore";
import { useNotificationStore } from "./stores/notificationStore";
import echo from "./echo";
import LoginPage from "./views/LoginPage.vue";
import NotificationBell from "./components/notifications/NotificationBell.vue";

// Standard Views
import LiveTelemetry from "./views/LiveTelemetry.vue";
const StrangerSnapsMonitor = defineAsyncComponent(
    () => import("./views/StrangerSnapsMonitor.vue"),
);
const DeviceAlertsCenter = defineAsyncComponent(
    () => import("./views/DeviceAlertsCenter.vue"),
);
const PersonnelManager = defineAsyncComponent(
    () => import("./views/PersonnelManager.vue"),
);
const DeviceManager = defineAsyncComponent(
    () => import("./views/DeviceManager.vue"),
);
const AccessLogsHistory = defineAsyncComponent(
    () => import("./views/AccessLogsHistory.vue"),
);
const SyncTasksMonitor = defineAsyncComponent(
    () => import("./views/SyncTasksMonitor.vue"),
);

// Async Loaded Hubs & Directories
const SettingsHub = defineAsyncComponent(
    () => import("./components/settings/SettingsHub.vue"),
);
const EmployeeDirectory = defineAsyncComponent(
    () => import("./components/employees/EmployeeDirectory.vue"),
);
const ScheduleHub = defineAsyncComponent(
    () => import("./components/schedules/ScheduleHub.vue"),
);
const AttendanceHub = defineAsyncComponent(
    () => import("./components/attendance/AttendanceHub.vue"),
);
const LeaveHub = defineAsyncComponent(
    () => import("./components/leave/LeaveHub.vue"),
);
const VisitorHub = defineAsyncComponent(
    () => import("./components/visitors/VisitorHub.vue"),
);
const ReportsHub = defineAsyncComponent(
    () => import("./components/reports/ReportsHub.vue"),
);

const store = useCameraStore();
const authStore = useAuthStore();
const attendanceStore = useAttendanceStore();
const visitorStore = useVisitorStore();
const notificationStore = useNotificationStore();

const currentTab = ref("live");
const userMenuOpen = ref(false);
const userMenuRef = ref(null);
const mobileMenuOpen = ref(false);

const handleClickOutside = (event) => {
    if (
        userMenuOpen.value &&
        userMenuRef.value &&
        !userMenuRef.value.contains(event.target)
    ) {
        userMenuOpen.value = false;
    }
};

const rawNavGroups = [
    {
        title: "Real-Time Telemetry",
        items: [
            {
                id: "live",
                label: "Live Telemetry",
                icon: "📹",
                description:
                    "Real-time facial recognition and pass-through event stream",
            },
            {
                id: "strangers",
                label: "Stranger Snaps",
                icon: "🎭",
                description:
                    "Unregistered face biometric detections and identity discovery",
            },
            {
                id: "alerts",
                label: "AI Alerts & Alarms",
                icon: "🚨",
                description:
                    "PPE safety, intrusion, fire/smoke, and edge AI security alarms",
            },
            {
                id: "devices",
                label: "Camera Devices",
                icon: "📡",
                description:
                    "Edge camera fleet management, health, and parameters",
            },
        ],
    },
    {
        title: "Workforce & Attendance",
        items: [
            {
                id: "attendance",
                label: "Attendance Engine",
                icon: "📊",
                description: "Biometric time clock, roster, and live punches",
                roles: ["admin", "hr-manager", "manager", "employee"],
            },
            {
                id: "leave",
                label: "Leave & Quotas",
                icon: "🏖️",
                description:
                    "Employee leave requests, approvals, and team calendar",
                roles: ["admin", "hr-manager", "manager", "employee"],
            },
            {
                id: "employees",
                label: "Employee Directory",
                icon: "👤",
                description:
                    "Staff profiles, biometric linkage, and department hierarchy",
                roles: ["admin", "hr-manager", "manager"],
            },
            {
                id: "schedules",
                label: "Work Schedules",
                icon: "🕐",
                description:
                    "Shift rules, roster assignments, and holiday calendar",
                roles: ["admin", "hr-manager", "manager"],
            },
        ],
    },
    {
        title: "Access & Face Library",
        items: [
            {
                id: "visitors",
                label: "Visitor Management",
                icon: "🏢",
                description:
                    "Visitor check-in, badges, and temporary camera whitelists",
                roles: ["admin", "receptionist", "security", "hr-manager"],
            },
            {
                id: "personnel",
                label: "Personnel & Faces",
                icon: "👥",
                description:
                    "Whitelist and blacklist identity database synchronized to cameras",
            },
            {
                id: "logs",
                label: "Access Audit Logs",
                icon: "📋",
                description:
                    "Historical verification log search, filters, and records",
            },
        ],
    },
    {
        title: "System & Analytics",
        items: [
            {
                id: "reports",
                label: "Reports & Payroll",
                icon: "📈",
                description:
                    "Attendance metrics, department analytics, and payroll export",
                roles: ["admin", "hr-manager", "manager"],
            },
            {
                id: "sync",
                label: "Sync Outbox Queue",
                icon: "⚡",
                description:
                    "Asynchronous edge camera dispatch job queue tracking",
            },
            {
                id: "settings",
                label: "Settings & Org",
                icon: "⚙️",
                description:
                    "System parameters, department hierarchy, and audit logs",
                requiresAdmin: true,
            },
        ],
    },
];

const navGroups = computed(() => {
    return rawNavGroups
        .map((group) => {
            const filteredItems = group.items.filter((item) => {
                if (item.requiresAdmin && !authStore.isAdmin) return false;
                if (item.roles && !authStore.hasAnyRole(item.roles))
                    return false;
                return true;
            });
            return { ...group, items: filteredItems };
        })
        .filter((group) => group.items.length > 0);
});

const allTabsFlat = computed(() => {
    return rawNavGroups.flatMap((g) => g.items);
});

const currentTabMeta = computed(() => {
    return (
        allTabsFlat.value.find((t) => t.id === currentTab.value) || {
            id: currentTab.value,
            label: "Dashboard",
            icon: "📹",
            description: "Biometric Access Control & Edge Stream Processor",
        }
    );
});

const switchTab = (tabId) => {
    currentTab.value = tabId;
    mobileMenuOpen.value = false;
};

const handleLogout = async () => {
    userMenuOpen.value = false;
    await authStore.logout();
};

let isTelemetryInitialized = false;

const initTelemetry = () => {
    if (isTelemetryInitialized) return;
    isTelemetryInitialized = true;

    store.fetchStats();
    store.fetchDevices();
    store.fetchRecentLogs();
    notificationStore.fetchNotifications();

    echo.private("access-logs")
        .listen(".AccessLogReceived", (e) => store.addLiveLog(e));

    echo.private("stranger-snaps")
        .listen(".StrangerSnapReceived", (e) => store.addStrangerSnap(e));

    echo.private("device-alerts")
        .listen(".DeviceAlertReceived", (e) => store.addDeviceAlert(e))
        .listen(".DeviceAlertUpdated", (e) => store.updateAlertStatus(e));

    echo.private("device-status")
        .listen(".DeviceStatusUpdated", (e) => store.updateDeviceStatus(e));

    echo.private("personnel")
        .listen(".PersonnelUpdated", (e) => store.handlePersonnelUpdated(e));

    echo.private("sync-tasks")
        .listen(".SyncTaskUpdated", (e) => store.handleSyncTaskUpdated(e));

    echo.private("attendance")
        .listen(".AttendancePunchReceived", (e) =>
            attendanceStore.handleLivePunch(e),
        );

    echo.private("visitors")
        .listen(".VisitorCheckedIn", (e) =>
            visitorStore.handleLiveVisitorCheckIn(e),
        )
        .listen(".VisitorCheckedOut", (e) =>
            visitorStore.handleLiveVisitorCheckOut(e),
        );

    if (authStore.user?.id) {
        echo.private(`notifications.${authStore.user.id}`)
            .listen(".NotificationCreated", (e) =>
                notificationStore.handleLiveNotification(e),
            );
    }

    if (echo.connector?.pusher?.connection) {
        if (!window.onEchoConnected) {
            window.onEchoConnected = () => { store.wsConnected = true; };
            window.onEchoDisconnected = () => { store.wsConnected = false; };
            window.onEchoConnecting = () => { store.wsConnected = false; };
        }
        echo.connector.pusher.connection.bind("connected", window.onEchoConnected);
        echo.connector.pusher.connection.bind("disconnected", window.onEchoDisconnected);
        echo.connector.pusher.connection.bind("connecting", window.onEchoConnecting);
        
        if (echo.connector.pusher.connection.state === "connected") {
            store.wsConnected = true;
        }
    }
};

const cleanupTelemetry = () => {
    if (!isTelemetryInitialized) return;
    isTelemetryInitialized = false;

    if (echo.connector?.pusher?.connection) {
        if (window.onEchoConnected) echo.connector.pusher.connection.unbind("connected", window.onEchoConnected);
        if (window.onEchoDisconnected) echo.connector.pusher.connection.unbind("disconnected", window.onEchoDisconnected);
        if (window.onEchoConnecting) echo.connector.pusher.connection.unbind("connecting", window.onEchoConnecting);
    }

    echo.leave("access-logs");
    echo.leave("stranger-snaps");
    echo.leave("device-alerts");
    echo.leave("device-status");
    echo.leave("personnel");
    echo.leave("sync-tasks");
    echo.leave("attendance");
    echo.leave("visitors");
    if (authStore.user?.id) {
        echo.leave(`notifications.${authStore.user.id}`);
    }
};

onMounted(async () => {
    window.addEventListener("click", handleClickOutside);
    await authStore.initAuth();
    if (authStore.isAuthenticated) {
        initTelemetry();
    }
});

watch(
    () => authStore.isAuthenticated,
    (isAuthed) => {
        if (isAuthed) {
            initTelemetry();
        } else {
            cleanupTelemetry();
        }
    },
);

watch(
    navGroups,
    (groups) => {
        if (!authStore.isAuthenticated) return;
        const allowedIds = groups.flatMap((g) => g.items.map((i) => i.id));
        if (allowedIds.length > 0 && !allowedIds.includes(currentTab.value)) {
            currentTab.value = allowedIds[0];
        }
    },
    { immediate: true },
);

onUnmounted(() => {
    window.removeEventListener("click", handleClickOutside);
    cleanupTelemetry();
});
</script>
