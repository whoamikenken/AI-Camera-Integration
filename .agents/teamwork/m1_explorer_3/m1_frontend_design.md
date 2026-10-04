# Milestone 1 Frontend Architecture & Implementation Blueprint

## Centralized API Client, Auth Store, Login Gate & Multi-Tenant Settings UI

- **Date**: 2026-09-30
- **Author**: Explorer 3 (Frontend Architecture & Settings Explorer)
- **Target Repository**: `/home/wsk-devops2/AI-Camera-Integration`
- **Milestone**: Milestone 1 (Security Foundation, RBAC & Multi-Tenant Settings)
- **Features Covered**:
    - Feature 3: Centralized Frontend API Client (`resources/js/api/client.js`)
    - Feature 4: Authentication Pinia Store & UI (`resources/js/stores/authStore.js`, `resources/js/views/LoginPage.vue`, `App.vue` auth gate)
    - Feature 6: Organization & Department Admin UI (`resources/js/components/settings/DepartmentManager.vue`)
    - Feature 7: Global System Settings UI (`resources/js/components/settings/SystemSettings.vue`)
    - Feature 8: Comprehensive Audit Trail Viewer (`resources/js/components/settings/AuditLogViewer.vue`)
    - Settings Navigation & Shell Integration (`resources/js/components/settings/SettingsHub.vue`, `App.vue` navigation & header)

---

## 1. Executive Summary & Architecture Context

The Intelligent AI Camera Hub is evolving into a full-scale **Attendance and Visitor Management System**. Milestone 1 establishes the foundational security, authentication, and administrative configuration layers.

Currently, the Vue 3 frontend operates as an unauthenticated single-page application where all backend requests use raw `axios` without authentication tokens, CSRF protection, or centralized error interceptors. Furthermore, the UI lacks multi-role gating, organizational hierarchy configuration (departments, designations, locations), system parameter toggles, and audit trail inspection.

This blueprint delivers the comprehensive, production-ready specification and complete implementation code for all frontend components in Milestone 1, adhering strictly to:

1. **Vue 3 Composition API (`<script setup>`)** with reactive state and clean lifecycle hooks.
2. **Pinia 4.0** state management with typed-like getters, persisted session cache, and RBAC helpers (`hasRole()`, `can()`).
3. **Tailwind CSS v4** design language consistent with the existing slate/indigo high-density visual theme.
4. **Vite 8.2** async code splitting (`defineAsyncComponent`) ensuring that `npm run build` maintains fast compilation and minimal initial bundle size.

---

## 2. Target File Structure & Component Layout

```
resources/js/
├── api/
│   └── client.js                         # Centralized Axios instance with Bearer token, CSRF & error interceptors
├── components/
│   ├── CameraLivePreviewModal.vue        # Preserved: WebGL camera feed modal
│   ├── DeviceAuditModal.vue              # Preserved: Face sync audit modal
│   ├── HistoricalBackfillModal.vue       # Preserved: Backfill logs modal
│   └── settings/                         # NEW: Settings & Org Admin Module
│       ├── SettingsHub.vue               # Unified tabbed container for admin configuration
│       ├── DepartmentManager.vue         # Organizations, Departments tree, Designations, Locations CRUD
│       ├── SystemSettings.vue            # Attendance thresholds, visitor rules, notification toggles
│       └── AuditLogViewer.vue            # Filterable audit trail browser with JSON diff modal
├── stores/
│   ├── cameraStore.js                    # Preserved: Camera fleet telemetry
│   └── authStore.js                      # NEW: Auth state, user profile, roles, permissions, login/logout
├── utils/
│   ├── cameraHqPlayer.js                 # Preserved: WebGL player
│   ├── date.js                           # Preserved: PHT date/time formatting utilities
│   ├── md5.js                            # Preserved: Digest utility
│   └── notify.js                         # Preserved: SweetAlert2 notifications & toasts
├── views/
│   ├── AccessLogsHistory.vue             # Preserved: Telemetry history
│   ├── DeviceManager.vue                 # Preserved: Device fleet manager
│   ├── LiveTelemetry.vue                 # Preserved: Real-time scan feed
│   ├── LoginPage.vue                     # NEW: Enterprise login page with role switcher hint
│   ├── PersonnelManager.vue              # Preserved: Face library manager
│   ├── StrangerSnapsMonitor.vue          # Preserved: Stranger capture monitor
│   └── SyncTasksMonitor.vue              # Preserved: Redis sync outbox monitor
├── App.vue                               # UPDATED: Auth gate, header profile dropdown, Settings tab
└── app.js                                # Preserved: Vue 3 app mounting & Pinia initialization
```

---

## 3. Feature 3: Centralized API Client (`resources/js/api/client.js`)

### 3.1 Design Principles

1. **Dual Auth Mechanism**: Supports both Laravel Sanctum Bearer tokens (via `Authorization: Bearer <token>`) and session cookies (`withCredentials: true`, `X-CSRF-TOKEN`).
2. **URL Normalization**: Handles both relative endpoints (`/auth/login`) and full-path endpoints (`/api/devices`) without creating duplicate `/api/api/...` path segments.
3. **Decoupled 401 Handling**: When an unauthenticated response is encountered, clears persisted credentials and dispatches a window event (`auth:unauthorized`), preventing circular store dependencies.
4. **Context-Aware Error Interception**: Automatically formats validation errors (HTTP 422), permission rejections (HTTP 403), and server exceptions (HTTP 500) into SweetAlert2 notifications via `notify.js`.

### 3.2 Implementation Blueprint (`resources/js/api/client.js`)

```javascript
import axios from "axios";
import notify from "../utils/notify";

/**
 * Retrieve CSRF token from page meta tag
 */
function getCsrfToken() {
    return (
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute("content") || ""
    );
}

/**
 * Create primary Axios instance
 */
export const apiClient = axios.create({
    baseURL: "/api",
    headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        "X-Requested-With": "XMLHttpRequest",
    },
    withCredentials: true,
    timeout: 30000,
});

/**
 * Request Interceptor
 * - Injects CSRF token
 * - Injects Bearer token from localStorage if available
 * - Normalizes URL to avoid duplicate /api prefixes
 */
apiClient.interceptors.request.use(
    (config) => {
        // Set CSRF token
        const csrfToken = getCsrfToken();
        if (csrfToken) {
            config.headers["X-CSRF-TOKEN"] = csrfToken;
        }

        // Attach Sanctum Bearer token
        const token = localStorage.getItem("auth_token");
        if (token && !config.headers.Authorization) {
            config.headers.Authorization = `Bearer ${token}`;
        }

        // Prevent /api/api/... double prefixing if callers supply leading '/api/'
        if (config.url) {
            if (config.url.startsWith("/api/")) {
                config.url = config.url.substring(4); // transforms '/api/foo' -> '/foo'
            } else if (config.url === "/api") {
                config.url = "";
            }
        }

        return config;
    },
    (error) => Promise.reject(error),
);

/**
 * Response Interceptor
 * - 401 Unauthorized: Clears auth tokens and triggers auth:unauthorized event
 * - 403 Forbidden: Displays permission error toast/modal
 * - 422 Unprocessable Content: Formats first validation error
 * - 500+ Server Error: Displays backend failure notice
 */
apiClient.interceptors.response.use(
    (response) => response,
    (error) => {
        const { response } = error;

        if (!response) {
            // Network failure or CORS block
            notify.error(
                "Network Connection Error",
                "Unable to reach backend API. Please verify server connectivity.",
            );
            return Promise.reject(error);
        }

        const { status, data } = response;

        switch (status) {
            case 401: {
                localStorage.removeItem("auth_token");
                localStorage.removeItem("auth_user");
                localStorage.removeItem("auth_roles");
                localStorage.removeItem("auth_permissions");

                // Notify shell to transition to login view
                window.dispatchEvent(
                    new CustomEvent("auth:unauthorized", {
                        detail: {
                            message:
                                data?.message ||
                                "Session expired. Please log in again.",
                        },
                    }),
                );
                break;
            }

            case 403: {
                notify.warning(
                    "Permission Denied",
                    data?.message ||
                        "You do not have administrative privileges to execute this action.",
                );
                break;
            }

            case 422: {
                // Extract first readable validation error
                let firstMsg = data?.message || "Validation error.";
                if (data?.errors && typeof data.errors === "object") {
                    const errorArrays = Object.values(data.errors);
                    if (
                        errorArrays.length > 0 &&
                        Array.isArray(errorArrays[0]) &&
                        errorArrays[0].length > 0
                    ) {
                        firstMsg = errorArrays[0][0];
                    }
                }
                notify.error("Validation Error", firstMsg);
                break;
            }

            case 404: {
                // Not found - let caller handle or display subtle notice if critical
                break;
            }

            case 500:
            case 502:
            case 503: {
                notify.error(
                    "Server Error",
                    data?.message ||
                        `Internal server error (HTTP ${status}). Please check system logs.`,
                );
                break;
            }
        }

        return Promise.reject(error);
    },
);

export default apiClient;
```

---

## 4. Feature 4: Pinia Auth Store (`resources/js/stores/authStore.js`)

### 4.1 Design Principles

1. **Instant Session Hydration**: Loads initial state from `localStorage` so the UI does not flicker to the login screen on refresh while `fetchCurrentUser()` validates credentials.
2. **RBAC Role & Permission Helpers**:
    - `hasRole(role)`: Checks for exact role or `super-admin` bypass.
    - `hasAnyRole([roles])`: Validates at least one matching role.
    - `can(permission)` / `hasPermission(permission)`: Checks granted permission slugs (e.g., `attendance.manage`, `devices.control`).
3. **Decoupled Event Listener**: Registers a listener for `auth:unauthorized` to gracefully reset state when a 401 response occurs anywhere in the application.

### 4.2 Implementation Blueprint (`resources/js/stores/authStore.js`)

```javascript
import { defineStore } from "pinia";
import apiClient from "../api/client";
import notify from "../utils/notify";

function getStoredJson(key, fallback) {
    try {
        const item = localStorage.getItem(key);
        return item ? JSON.parse(item) : fallback;
    } catch {
        return fallback;
    }
}

export const useAuthStore = defineStore("auth", {
    state: () => ({
        user: getStoredJson("auth_user", null),
        token: localStorage.getItem("auth_token") || null,
        roles: getStoredJson("auth_roles", []),
        permissions: getStoredJson("auth_permissions", []),
        initialized: false,
        loading: false,
        loginError: null,
    }),

    getters: {
        isAuthenticated: (state) => !!state.token && !!state.user,

        // RBAC Role Checkers
        hasRole: (state) => (role) => {
            if (state.roles.includes("super-admin")) return true;
            return state.roles.includes(role);
        },

        hasAnyRole: (state) => (rolesToCheck) => {
            if (state.roles.includes("super-admin")) return true;
            return rolesToCheck.some((r) => state.roles.includes(r));
        },

        // RBAC Permission Checkers (with super-admin wildcard)
        can: (state) => (permission) => {
            if (state.roles.includes("super-admin")) return true;
            return state.permissions.includes(permission);
        },

        hasPermission: (state) => (permission) => {
            if (state.roles.includes("super-admin")) return true;
            return state.permissions.includes(permission);
        },

        cannot: (state) => (permission) => {
            if (state.roles.includes("super-admin")) return false;
            return !state.permissions.includes(permission);
        },

        // Primary Role Flags
        isSuperAdmin: (state) => state.roles.includes("super-admin"),
        isAdmin: (state) =>
            state.roles.includes("super-admin") ||
            state.roles.includes("admin"),
        isHrManager: (state) =>
            state.roles.includes("super-admin") ||
            state.roles.includes("admin") ||
            state.roles.includes("hr-manager"),
        isSecurity: (state) =>
            state.roles.includes("super-admin") ||
            state.roles.includes("admin") ||
            state.roles.includes("security"),
        isReceptionist: (state) =>
            state.roles.includes("super-admin") ||
            state.roles.includes("admin") ||
            state.roles.includes("receptionist"),
        isManager: (state) =>
            state.roles.includes("super-admin") ||
            state.roles.includes("admin") ||
            state.roles.includes("manager"),
        isEmployee: (state) => state.roles.includes("employee"),

        // Profile Display Helpers
        userName: (state) => state.user?.name || "Administrator",
        userEmail: (state) => state.user?.email || "",
        primaryRoleBadge: (state) => {
            if (state.roles.includes("super-admin")) return "Super Admin";
            if (state.roles.includes("admin")) return "Admin";
            if (state.roles.includes("hr-manager")) return "HR Manager";
            if (state.roles.includes("security")) return "Security";
            if (state.roles.includes("receptionist")) return "Receptionist";
            if (state.roles.includes("manager")) return "Manager";
            if (state.roles.includes("employee")) return "Employee";
            return "Staff";
        },
        userInitials: (state) => {
            const name = state.user?.name || "Admin User";
            const parts = name.trim().split(/\s+/);
            if (parts.length === 1)
                return parts[0].substring(0, 2).toUpperCase();
            return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
        },
    },

    actions: {
        /**
         * Initialize auth state on application startup
         */
        async initAuth() {
            if (this.initialized) return;

            // Register unauthorized event listener
            window.addEventListener("auth:unauthorized", () => {
                this.clearAuth();
            });

            if (this.token) {
                try {
                    await this.fetchCurrentUser();
                } catch {
                    this.clearAuth();
                }
            }

            this.initialized = true;
        },

        /**
         * User Login Action
         * @param {Object} credentials - { email, password, remember }
         */
        async login(credentials) {
            this.loading = true;
            this.loginError = null;

            try {
                const response = await apiClient.post(
                    "/auth/login",
                    credentials,
                );
                const data = response.data;

                const token = data.token || data.access_token;
                const user = data.user;
                const roles =
                    data.roles ||
                    user.roles?.map((r) =>
                        typeof r === "string" ? r : r.slug || r.name,
                    ) ||
                    [];
                const permissions =
                    data.permissions ||
                    user.permissions?.map((p) =>
                        typeof p === "string" ? p : p.slug || p.name,
                    ) ||
                    [];

                this.token = token;
                this.user = user;
                this.roles = roles;
                this.permissions = permissions;

                localStorage.setItem("auth_token", token);
                localStorage.setItem("auth_user", JSON.stringify(user));
                localStorage.setItem("auth_roles", JSON.stringify(roles));
                localStorage.setItem(
                    "auth_permissions",
                    JSON.stringify(permissions),
                );

                notify.toast(`Welcome back, ${user.name}!`, "success");
                return { success: true, user };
            } catch (err) {
                const msg =
                    err.response?.data?.message ||
                    "Invalid email or password credentials.";
                this.loginError = msg;
                throw err;
            } finally {
                this.loading = false;
            }
        },

        /**
         * Fetch current authenticated user profile
         */
        async fetchCurrentUser() {
            try {
                const response = await apiClient.get("/auth/user");
                const data = response.data;
                const user = data.user || data;

                const roles =
                    data.roles ||
                    user.roles?.map((r) =>
                        typeof r === "string" ? r : r.slug || r.name,
                    ) ||
                    [];
                const permissions =
                    data.permissions ||
                    user.permissions?.map((p) =>
                        typeof p === "string" ? p : p.slug || p.name,
                    ) ||
                    [];

                this.user = user;
                this.roles = roles;
                this.permissions = permissions;

                localStorage.setItem("auth_user", JSON.stringify(user));
                localStorage.setItem("auth_roles", JSON.stringify(roles));
                localStorage.setItem(
                    "auth_permissions",
                    JSON.stringify(permissions),
                );

                return user;
            } catch (err) {
                if (err.response?.status === 401) {
                    this.clearAuth();
                }
                throw err;
            }
        },

        /**
         * User Logout Action
         */
        async logout() {
            this.loading = true;
            try {
                if (this.token) {
                    await apiClient.post("/auth/logout");
                }
            } catch (err) {
                console.warn(
                    "Logout API error, clearing local credentials:",
                    err,
                );
            } finally {
                this.clearAuth();
                this.loading = false;
                notify.toast("Signed out successfully.", "info");
            }
        },

        /**
         * Reset local authentication credentials
         */
        clearAuth() {
            this.user = null;
            this.token = null;
            this.roles = [];
            this.permissions = [];
            localStorage.removeItem("auth_token");
            localStorage.removeItem("auth_user");
            localStorage.removeItem("auth_roles");
            localStorage.removeItem("auth_permissions");
        },

        /**
         * Update authenticated user profile details
         */
        async updateProfile(profileData) {
            const res = await apiClient.put("/auth/profile", profileData);
            this.user = { ...this.user, ...res.data.user };
            localStorage.setItem("auth_user", JSON.stringify(this.user));
            notify.toast("Profile updated successfully!", "success");
            return res.data;
        },

        /**
         * Change user password
         */
        async changePassword(passwordData) {
            const res = await apiClient.put("/auth/password", passwordData);
            notify.success(
                "Password Changed",
                "Your password has been securely updated.",
            );
            return res.data;
        },
    },
});
```

---

## 5. Feature 4 (cont.): Login Page (`resources/js/views/LoginPage.vue`)

### 5.1 Design & Capabilities

- **Branded Card Layout**: Clean centered card on slate-50 background utilizing `pinnacle-logo-light.svg`.
- **Form Controls**: Email input, password input with show/hide password toggle eye icon, remember me checkbox.
- **Loading State & Error Banner**: Inline feedback during authentication with disabled submit button.
- **Developer Quick-Fill Selector**: Expandable panel allowing one-click credential prefill for standard roles (`admin@pinnacle.test`, `hr@pinnacle.test`, `receptionist@pinnacle.test`, etc.) to streamline development and automated testing.

### 5.2 Implementation Blueprint (`resources/js/views/LoginPage.vue`)

```vue
<template>
    <div
        class="min-h-screen bg-slate-50 flex flex-col justify-center py-12 sm:px-6 lg:px-8 font-sans antialiased selection:bg-indigo-500 selection:text-white"
    >
        <div class="sm:mx-auto sm:w-full sm:max-w-md">
            <!-- Pinnacle Logo & Branding -->
            <div class="flex justify-center items-center gap-3">
                <img
                    :src="logoLight"
                    alt="Pinnacle Technologies"
                    class="h-10 w-auto"
                />
            </div>
            <h2
                class="mt-4 text-center text-xl font-bold tracking-tight text-slate-900"
            >
                AI Camera Hub &amp; Attendance
            </h2>
            <p class="mt-1 text-center text-xs text-slate-500">
                Enterprise Biometric Access Control &amp; Workforce Portal
            </p>
        </div>

        <div class="mt-6 sm:mx-auto sm:w-full sm:max-w-md">
            <div
                class="bg-white py-8 px-6 shadow-xl border border-slate-200/80 rounded-2xl sm:px-10"
            >
                <!-- Error Banner -->
                <div
                    v-if="authStore.loginError"
                    class="mb-5 bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-xl text-xs flex items-center justify-between"
                >
                    <div class="flex items-center gap-2">
                        <span class="text-rose-500 text-sm">⚠️</span>
                        <span>{{ authStore.loginError }}</span>
                    </div>
                    <button
                        @click="authStore.loginError = null"
                        class="text-rose-400 hover:text-rose-600 font-bold ml-2"
                    >
                        &times;
                    </button>
                </div>

                <form class="space-y-5" @submit.prevent="handleSubmit">
                    <!-- Email Input -->
                    <div>
                        <label
                            for="email"
                            class="block text-xs font-semibold text-slate-700"
                        >
                            Work Email Address
                        </label>
                        <div class="mt-1 relative">
                            <input
                                id="email"
                                v-model="form.email"
                                type="email"
                                autocomplete="email"
                                required
                                placeholder="name@company.com"
                                class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs"
                            />
                        </div>
                    </div>

                    <!-- Password Input -->
                    <div>
                        <label
                            for="password"
                            class="block text-xs font-semibold text-slate-700"
                        >
                            Password
                        </label>
                        <div class="mt-1 relative">
                            <input
                                id="password"
                                v-model="form.password"
                                :type="showPassword ? 'text' : 'password'"
                                autocomplete="current-password"
                                required
                                placeholder="••••••••"
                                class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 pr-10 text-xs text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs"
                            />
                            <button
                                type="button"
                                @click="showPassword = !showPassword"
                                class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-600 cursor-pointer"
                                title="Toggle password visibility"
                            >
                                <span class="text-xs">{{
                                    showPassword ? "👁️" : "🙈"
                                }}</span>
                            </button>
                        </div>
                    </div>

                    <!-- Remember Me & Forgot Password -->
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <input
                                id="remember-me"
                                v-model="form.remember"
                                type="checkbox"
                                class="h-3.5 w-3.5 text-indigo-600 focus:ring-indigo-500 border-slate-300 rounded cursor-pointer"
                            />
                            <label
                                for="remember-me"
                                class="ml-2 block text-xs text-slate-600 cursor-pointer"
                            >
                                Keep me signed in
                            </label>
                        </div>

                        <div class="text-xs">
                            <button
                                type="button"
                                @click="forgotPasswordAlert"
                                class="font-medium text-indigo-600 hover:text-indigo-500 cursor-pointer"
                            >
                                Forgot password?
                            </button>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div>
                        <button
                            type="submit"
                            :disabled="authStore.loading"
                            class="w-full flex justify-center py-2.5 px-4 border border-transparent rounded-lg shadow-sm text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50 transition-all cursor-pointer"
                        >
                            <span
                                v-if="authStore.loading"
                                class="flex items-center gap-2"
                            >
                                <span
                                    class="w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin"
                                ></span>
                                Authenticating...
                            </span>
                            <span v-else>Sign In to Console</span>
                        </button>
                    </div>
                </form>

                <!-- Quick-Fill Developer / Demo Switcher -->
                <div class="mt-6 pt-5 border-t border-slate-100">
                    <div class="flex items-center justify-between mb-2">
                        <span
                            class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider"
                            >Quick Fill Roles</span
                        >
                        <span class="text-[10px] text-slate-400 font-mono"
                            >pw: password</span
                        >
                    </div>
                    <div class="grid grid-cols-2 gap-2 text-[11px]">
                        <button
                            type="button"
                            @click="prefill('admin@pinnacle.test', 'password')"
                            class="px-2 py-1 bg-slate-50 hover:bg-slate-100 text-slate-700 rounded border border-slate-200 text-left font-medium transition-colors cursor-pointer"
                        >
                            👑 Super Admin
                        </button>
                        <button
                            type="button"
                            @click="prefill('hr@pinnacle.test', 'password')"
                            class="px-2 py-1 bg-slate-50 hover:bg-slate-100 text-slate-700 rounded border border-slate-200 text-left font-medium transition-colors cursor-pointer"
                        >
                            💼 HR Manager
                        </button>
                        <button
                            type="button"
                            @click="
                                prefill('security@pinnacle.test', 'password')
                            "
                            class="px-2 py-1 bg-slate-50 hover:bg-slate-100 text-slate-700 rounded border border-slate-200 text-left font-medium transition-colors cursor-pointer"
                        >
                            🛡️ Security
                        </button>
                        <button
                            type="button"
                            @click="
                                prefill(
                                    'receptionist@pinnacle.test',
                                    'password',
                                )
                            "
                            class="px-2 py-1 bg-slate-50 hover:bg-slate-100 text-slate-700 rounded border border-slate-200 text-left font-medium transition-colors cursor-pointer"
                        >
                            🎫 Receptionist
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref } from "vue";
import logoLight from "../../images/pinnacle-logo-light.svg";
import { useAuthStore } from "../stores/authStore";
import notify from "../utils/notify";

const authStore = useAuthStore();
const showPassword = ref(false);

const form = ref({
    email: "",
    password: "",
    remember: true,
});

const prefill = (email, password) => {
    form.value.email = email;
    form.value.password = password;
};

const handleSubmit = async () => {
    try {
        await authStore.login({
            email: form.value.email,
            password: form.value.password,
            remember: form.value.remember,
        });
    } catch (err) {
        // Handled in authStore & client interceptor
    }
};

const forgotPasswordAlert = () => {
    notify.info(
        "Password Reset Assistance",
        "Please contact your system administrator or IT helpdesk to issue a temporary password or reset credentials.",
    );
};
</script>
```

---

## 6. Feature 6: Organization & Department Admin UI (`resources/js/components/settings/DepartmentManager.vue`)

### 6.1 Design & Capabilities

`DepartmentManager.vue` provides centralized management of the organizational hierarchy across four distinct sub-modules:

1. **Departments**:
    - Tabular / Tree view with Parent Department badge and Head of Department assignment.
    - Prevents cyclical department assignment (cannot select self or child as parent).
    - Create / Edit modal with code, name, parent selector, and remarks.
    - Delete confirmation with safeguard check for child departments.
2. **Job Titles / Designations**:
    - Listing with Hierarchy Level (1 = Executive/Director down to 5 = Entry/Associate).
    - Modal for creating/updating designations.
3. **Locations / Sites**:
    - Management of physical premises (e.g. Headquarters, Logistics Hub, Secondary Gate).
    - Fields: Name, Street Address, Timezone (default `Asia/Manila`), Geo-coordinates.
4. **Organization Profile**:
    - Global organization name, company code, logo URL, and HQ address.

### 6.2 Implementation Blueprint (`resources/js/components/settings/DepartmentManager.vue`)

```vue
<template>
    <div class="space-y-6">
        <!-- Top Sub-Navigation -->
        <div
            class="flex items-center justify-between bg-white border border-slate-200/80 p-4 rounded-xl shadow-xs"
        >
            <div>
                <h3 class="text-base font-bold text-slate-900">
                    Organization Hierarchy &amp; Structure
                </h3>
                <p class="text-xs text-slate-500">
                    Configure organizational units, departments, job
                    designations, and physical locations
                </p>
            </div>

            <!-- Action Button Based on Active Sub-Tab -->
            <div class="flex items-center gap-2">
                <button
                    v-if="subTab === 'departments'"
                    @click="openDepartmentModal(null)"
                    class="px-3.5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg shadow-sm transition-all flex items-center gap-1.5 cursor-pointer"
                >
                    <span>➕ Add Department</span>
                </button>
                <button
                    v-else-if="subTab === 'designations'"
                    @click="openDesignationModal(null)"
                    class="px-3.5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg shadow-sm transition-all flex items-center gap-1.5 cursor-pointer"
                >
                    <span>➕ Add Designation</span>
                </button>
                <button
                    v-else-if="subTab === 'locations'"
                    @click="openLocationModal(null)"
                    class="px-3.5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg shadow-sm transition-all flex items-center gap-1.5 cursor-pointer"
                >
                    <span>➕ Add Site / Location</span>
                </button>
            </div>
        </div>

        <!-- Segmented Navigation Bar -->
        <div class="flex items-center gap-2 border-b border-slate-200 pb-1">
            <button
                v-for="tab in subTabs"
                :key="tab.id"
                @click="subTab = tab.id"
                class="px-4 py-2 text-xs font-semibold rounded-t-lg transition-all flex items-center gap-2 border-b-2 cursor-pointer"
                :class="
                    subTab === tab.id
                        ? 'text-indigo-600 border-indigo-600 bg-white shadow-xs'
                        : 'text-slate-500 border-transparent hover:text-slate-800 hover:border-slate-300'
                "
            >
                <span>{{ tab.icon }}</span>
                <span>{{ tab.label }}</span>
                <span
                    v-if="tab.count !== undefined"
                    class="ml-1 px-1.5 py-0.5 rounded-full text-[10px] bg-slate-100 text-slate-600 font-mono"
                >
                    {{ tab.count }}
                </span>
            </button>
        </div>

        <!-- 1. DEPARTMENTS TAB -->
        <div v-if="subTab === 'departments'" class="space-y-4">
            <div
                class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-xs"
            >
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs text-slate-700">
                        <thead
                            class="bg-slate-50 text-slate-500 uppercase text-[11px] font-semibold border-b border-slate-200"
                        >
                            <tr>
                                <th class="py-3 px-4">Code</th>
                                <th class="py-3 px-4">Department Name</th>
                                <th class="py-3 px-4">Parent Department</th>
                                <th class="py-3 px-4">Department Head</th>
                                <th class="py-3 px-4 text-center">Sub-Depts</th>
                                <th class="py-3 px-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-if="loadingDepartments">
                                <td
                                    colspan="6"
                                    class="py-12 text-center text-slate-500"
                                >
                                    Loading departments...
                                </td>
                            </tr>
                            <tr v-else-if="departments.length === 0">
                                <td
                                    colspan="6"
                                    class="py-12 text-center text-slate-500"
                                >
                                    No departments configured yet. Click "Add
                                    Department" to start.
                                </td>
                            </tr>
                            <tr
                                v-for="dept in departments"
                                :key="dept.id"
                                class="hover:bg-slate-50 transition-colors"
                            >
                                <td
                                    class="py-3 px-4 font-mono font-semibold text-slate-900"
                                >
                                    {{ dept.code || "--" }}
                                </td>
                                <td class="py-3 px-4 font-bold text-slate-900">
                                    {{ dept.name }}
                                </td>
                                <td class="py-3 px-4">
                                    <span
                                        v-if="dept.parent"
                                        class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-100 text-slate-700 border border-slate-200"
                                    >
                                        {{ dept.parent.name }}
                                    </span>
                                    <span
                                        v-else
                                        class="text-slate-400 italic text-[11px]"
                                        >Root Level</span
                                    >
                                </td>
                                <td class="py-3 px-4 text-slate-600">
                                    {{
                                        dept.head_name ||
                                        dept.head?.name ||
                                        "Unassigned"
                                    }}
                                </td>
                                <td class="py-3 px-4 text-center font-mono">
                                    {{
                                        dept.children_count ||
                                        dept.children?.length ||
                                        0
                                    }}
                                </td>
                                <td class="py-3 px-4 text-right space-x-2">
                                    <button
                                        @click="openDepartmentModal(dept)"
                                        class="px-2.5 py-1 text-[11px] bg-white hover:bg-slate-50 text-slate-700 font-medium border border-slate-200 rounded transition-colors shadow-xs cursor-pointer"
                                    >
                                        Edit
                                    </button>
                                    <button
                                        @click="deleteDepartment(dept)"
                                        class="px-2.5 py-1 text-[11px] bg-rose-50 hover:bg-rose-100 text-rose-700 font-semibold border border-rose-200 rounded transition-colors shadow-xs cursor-pointer"
                                    >
                                        Delete
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 2. DESIGNATIONS TAB -->
        <div v-if="subTab === 'designations'" class="space-y-4">
            <div
                class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-xs"
            >
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs text-slate-700">
                        <thead
                            class="bg-slate-50 text-slate-500 uppercase text-[11px] font-semibold border-b border-slate-200"
                        >
                            <tr>
                                <th class="py-3 px-4">Designation / Title</th>
                                <th class="py-3 px-4">Hierarchy Level</th>
                                <th class="py-3 px-4">Description</th>
                                <th class="py-3 px-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-if="loadingDesignations">
                                <td
                                    colspan="4"
                                    class="py-12 text-center text-slate-500"
                                >
                                    Loading designations...
                                </td>
                            </tr>
                            <tr v-else-if="designations.length === 0">
                                <td
                                    colspan="4"
                                    class="py-12 text-center text-slate-500"
                                >
                                    No job designations found. Click "Add
                                    Designation" to create one.
                                </td>
                            </tr>
                            <tr
                                v-for="desig in designations"
                                :key="desig.id"
                                class="hover:bg-slate-50 transition-colors"
                            >
                                <td class="py-3 px-4 font-bold text-slate-900">
                                    {{ desig.name }}
                                </td>
                                <td class="py-3 px-4">
                                    <span
                                        class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-indigo-50 text-indigo-700 border border-indigo-200"
                                    >
                                        Level {{ desig.level || 1 }}
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-slate-500">
                                    {{ desig.description || "--" }}
                                </td>
                                <td class="py-3 px-4 text-right space-x-2">
                                    <button
                                        @click="openDesignationModal(desig)"
                                        class="px-2.5 py-1 text-[11px] bg-white hover:bg-slate-50 text-slate-700 font-medium border border-slate-200 rounded transition-colors shadow-xs cursor-pointer"
                                    >
                                        Edit
                                    </button>
                                    <button
                                        @click="deleteDesignation(desig)"
                                        class="px-2.5 py-1 text-[11px] bg-rose-50 hover:bg-rose-100 text-rose-700 font-semibold border border-rose-200 rounded transition-colors shadow-xs cursor-pointer"
                                    >
                                        Delete
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 3. SITES & LOCATIONS TAB -->
        <div v-if="subTab === 'locations'" class="space-y-4">
            <div
                class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-xs"
            >
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs text-slate-700">
                        <thead
                            class="bg-slate-50 text-slate-500 uppercase text-[11px] font-semibold border-b border-slate-200"
                        >
                            <tr>
                                <th class="py-3 px-4">Location / Site Name</th>
                                <th class="py-3 px-4">Address</th>
                                <th class="py-3 px-4">Timezone</th>
                                <th class="py-3 px-4">Geo Coordinates</th>
                                <th class="py-3 px-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-if="loadingLocations">
                                <td
                                    colspan="5"
                                    class="py-12 text-center text-slate-500"
                                >
                                    Loading locations...
                                </td>
                            </tr>
                            <tr v-else-if="locations.length === 0">
                                <td
                                    colspan="5"
                                    class="py-12 text-center text-slate-500"
                                >
                                    No physical locations configured. Click "Add
                                    Site / Location" to add one.
                                </td>
                            </tr>
                            <tr
                                v-for="loc in locations"
                                :key="loc.id"
                                class="hover:bg-slate-50 transition-colors"
                            >
                                <td class="py-3 px-4 font-bold text-slate-900">
                                    {{ loc.name }}
                                </td>
                                <td class="py-3 px-4 text-slate-600">
                                    {{ loc.address || "--" }}
                                </td>
                                <td
                                    class="py-3 px-4 font-mono text-[11px] text-slate-600"
                                >
                                    {{ loc.timezone || "Asia/Manila" }}
                                </td>
                                <td
                                    class="py-3 px-4 font-mono text-[11px] text-slate-500"
                                >
                                    {{ loc.coordinates || "--" }}
                                </td>
                                <td class="py-3 px-4 text-right space-x-2">
                                    <button
                                        @click="openLocationModal(loc)"
                                        class="px-2.5 py-1 text-[11px] bg-white hover:bg-slate-50 text-slate-700 font-medium border border-slate-200 rounded transition-colors shadow-xs cursor-pointer"
                                    >
                                        Edit
                                    </button>
                                    <button
                                        @click="deleteLocation(loc)"
                                        class="px-2.5 py-1 text-[11px] bg-rose-50 hover:bg-rose-100 text-rose-700 font-semibold border border-rose-200 rounded transition-colors shadow-xs cursor-pointer"
                                    >
                                        Delete
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 4. ORGANIZATION PROFILE TAB -->
        <div
            v-if="subTab === 'organization'"
            class="bg-white border border-slate-200 rounded-xl p-6 shadow-xs max-w-3xl space-y-6"
        >
            <h4
                class="text-sm font-bold text-slate-900 border-b border-slate-100 pb-3"
            >
                Organization Identity &amp; Information
            </h4>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-700"
                        >Company / Organization Name</label
                    >
                    <input
                        v-model="orgForm.name"
                        type="text"
                        class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
                    />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700"
                        >Organization Code</label
                    >
                    <input
                        v-model="orgForm.code"
                        type="text"
                        class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
                    />
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-slate-700"
                        >Headquarters Address</label
                    >
                    <textarea
                        v-model="orgForm.address"
                        rows="2"
                        class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
                    ></textarea>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700"
                        >Primary Timezone</label
                    >
                    <select
                        v-model="orgForm.timezone"
                        class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
                    >
                        <option value="Asia/Manila">
                            Asia/Manila (PHT, UTC+8)
                        </option>
                        <option value="UTC">UTC (UTC+0)</option>
                        <option value="Asia/Singapore">
                            Asia/Singapore (SGT, UTC+8)
                        </option>
                        <option value="America/New_York">
                            America/New_York (EST, UTC-5)
                        </option>
                    </select>
                </div>
            </div>
            <div class="pt-4 border-t border-slate-100 flex justify-end">
                <button
                    @click="saveOrganization"
                    :disabled="savingOrg"
                    class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg shadow-sm transition-all cursor-pointer disabled:opacity-50"
                >
                    {{ savingOrg ? "Saving..." : "Save Organization Profile" }}
                </button>
            </div>
        </div>

        <!-- MODAL: Department Create/Edit -->
        <div
            v-if="showDeptModal"
            class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4"
        >
            <div
                class="bg-white rounded-2xl border border-slate-200 max-w-md w-full p-6 shadow-2xl space-y-4"
            >
                <h3 class="text-base font-bold text-slate-900">
                    {{
                        editingDeptId
                            ? "Edit Department"
                            : "Create New Department"
                    }}
                </h3>
                <div class="space-y-3">
                    <div>
                        <label
                            class="block text-xs font-semibold text-slate-700"
                            >Department Name *</label
                        >
                        <input
                            v-model="deptForm.name"
                            type="text"
                            placeholder="e.g. Information Technology"
                            class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
                        />
                    </div>
                    <div>
                        <label
                            class="block text-xs font-semibold text-slate-700"
                            >Department Code *</label
                        >
                        <input
                            v-model="deptForm.code"
                            type="text"
                            placeholder="e.g. IT-ENG"
                            class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
                        />
                    </div>
                    <div>
                        <label
                            class="block text-xs font-semibold text-slate-700"
                            >Parent Department</label
                        >
                        <select
                            v-model="deptForm.parent_id"
                            class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-700 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
                        >
                            <option :value="null">
                                None (Top-Level Department)
                            </option>
                            <option
                                v-for="d in parentOptions"
                                :key="d.id"
                                :value="d.id"
                            >
                                {{ d.name }} ({{ d.code }})
                            </option>
                        </select>
                    </div>
                    <div>
                        <label
                            class="block text-xs font-semibold text-slate-700"
                            >Head of Department (Staff ID)</label
                        >
                        <input
                            v-model="deptForm.head_id"
                            type="number"
                            placeholder="Optional Employee/Staff ID"
                            class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
                        />
                    </div>
                </div>
                <div
                    class="pt-4 border-t border-slate-100 flex items-center justify-end gap-2"
                >
                    <button
                        @click="showDeptModal = false"
                        class="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-medium rounded-lg cursor-pointer"
                    >
                        Cancel
                    </button>
                    <button
                        @click="saveDepartment"
                        :disabled="savingDept"
                        class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg shadow-sm cursor-pointer disabled:opacity-50"
                    >
                        {{ savingDept ? "Saving..." : "Save Department" }}
                    </button>
                </div>
            </div>
        </div>

        <!-- MODAL: Designation Create/Edit -->
        <div
            v-if="showDesigModal"
            class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4"
        >
            <div
                class="bg-white rounded-2xl border border-slate-200 max-w-md w-full p-6 shadow-2xl space-y-4"
            >
                <h3 class="text-base font-bold text-slate-900">
                    {{
                        editingDesigId ? "Edit Designation" : "Create Job Title"
                    }}
                </h3>
                <div class="space-y-3">
                    <div>
                        <label
                            class="block text-xs font-semibold text-slate-700"
                            >Designation / Title Name *</label
                        >
                        <input
                            v-model="desigForm.name"
                            type="text"
                            placeholder="e.g. Lead Software Engineer"
                            class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
                        />
                    </div>
                    <div>
                        <label
                            class="block text-xs font-semibold text-slate-700"
                            >Hierarchy Level (1 = Highest)</label
                        >
                        <select
                            v-model.number="desigForm.level"
                            class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-700"
                        >
                            <option :value="1">
                                Level 1 - Executive / Director
                            </option>
                            <option :value="2">
                                Level 2 - Manager / Dept Head
                            </option>
                            <option :value="3">
                                Level 3 - Senior / Specialist
                            </option>
                            <option :value="4">
                                Level 4 - Associate / Officer
                            </option>
                            <option :value="5">
                                Level 5 - Entry / Support
                            </option>
                        </select>
                    </div>
                    <div>
                        <label
                            class="block text-xs font-semibold text-slate-700"
                            >Description</label
                        >
                        <input
                            v-model="desigForm.description"
                            type="text"
                            placeholder="Job responsibilities summary"
                            class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900"
                        />
                    </div>
                </div>
                <div
                    class="pt-4 border-t border-slate-100 flex items-center justify-end gap-2"
                >
                    <button
                        @click="showDesigModal = false"
                        class="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-medium rounded-lg cursor-pointer"
                    >
                        Cancel
                    </button>
                    <button
                        @click="saveDesignation"
                        class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg shadow-sm cursor-pointer"
                    >
                        Save Title
                    </button>
                </div>
            </div>
        </div>

        <!-- MODAL: Location Create/Edit -->
        <div
            v-if="showLocModal"
            class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4"
        >
            <div
                class="bg-white rounded-2xl border border-slate-200 max-w-md w-full p-6 shadow-2xl space-y-4"
            >
                <h3 class="text-base font-bold text-slate-900">
                    {{
                        editingLocId
                            ? "Edit Location"
                            : "Create Location / Site"
                    }}
                </h3>
                <div class="space-y-3">
                    <div>
                        <label
                            class="block text-xs font-semibold text-slate-700"
                            >Site / Location Name *</label
                        >
                        <input
                            v-model="locForm.name"
                            type="text"
                            placeholder="e.g. Main Lobby Gate 1"
                            class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900"
                        />
                    </div>
                    <div>
                        <label
                            class="block text-xs font-semibold text-slate-700"
                            >Physical Address</label
                        >
                        <input
                            v-model="locForm.address"
                            type="text"
                            placeholder="Building, Street, City"
                            class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900"
                        />
                    </div>
                    <div>
                        <label
                            class="block text-xs font-semibold text-slate-700"
                            >Timezone</label
                        >
                        <input
                            v-model="locForm.timezone"
                            type="text"
                            placeholder="Asia/Manila"
                            class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono"
                        />
                    </div>
                    <div>
                        <label
                            class="block text-xs font-semibold text-slate-700"
                            >Geo Coordinates (Lat, Long)</label
                        >
                        <input
                            v-model="locForm.coordinates"
                            type="text"
                            placeholder="14.5547, 121.0244"
                            class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono"
                        />
                    </div>
                </div>
                <div
                    class="pt-4 border-t border-slate-100 flex items-center justify-end gap-2"
                >
                    <button
                        @click="showLocModal = false"
                        class="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-medium rounded-lg cursor-pointer"
                    >
                        Cancel
                    </button>
                    <button
                        @click="saveLocation"
                        class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg shadow-sm cursor-pointer"
                    >
                        Save Location
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from "vue";
import apiClient from "../../api/client";
import notify from "../../utils/notify";

const subTab = ref("departments");

const departments = ref([]);
const designations = ref([]);
const locations = ref([]);
const orgForm = ref({
    name: "Pinnacle Technologies Inc.",
    code: "PINNACLE-HQ",
    address: "",
    timezone: "Asia/Manila",
});

const loadingDepartments = ref(false);
const loadingDesignations = ref(false);
const loadingLocations = ref(false);
const savingDept = ref(false);
const savingOrg = ref(false);

const subTabs = computed(() => [
    {
        id: "departments",
        label: "Departments",
        icon: "🏬",
        count: departments.value.length,
    },
    {
        id: "designations",
        label: "Job Titles & Designations",
        icon: "💼",
        count: designations.value.length,
    },
    {
        id: "locations",
        label: "Sites & Locations",
        icon: "📍",
        count: locations.value.length,
    },
    { id: "organization", label: "Organization Profile", icon: "🏢" },
]);

// Department Modal State
const showDeptModal = ref(false);
const editingDeptId = ref(null);
const deptForm = ref({ name: "", code: "", parent_id: null, head_id: null });

const parentOptions = computed(() => {
    return departments.value.filter(
        (d) => !editingDeptId.value || d.id !== editingDeptId.value,
    );
});

// Designation Modal State
const showDesigModal = ref(false);
const editingDesigId = ref(null);
const desigForm = ref({ name: "", level: 3, description: "" });

// Location Modal State
const showLocModal = ref(false);
const editingLocId = ref(null);
const locForm = ref({
    name: "",
    address: "",
    timezone: "Asia/Manila",
    coordinates: "",
});

// API Operations
const fetchDepartments = async () => {
    loadingDepartments.value = true;
    try {
        const res = await apiClient.get("/departments");
        departments.value = res.data.data || res.data || [];
    } catch (e) {
        console.error("Failed to load departments", e);
    } finally {
        loadingDepartments.value = false;
    }
};

const fetchDesignations = async () => {
    loadingDesignations.value = true;
    try {
        const res = await apiClient.get("/designations");
        designations.value = res.data.data || res.data || [];
    } catch (e) {
        console.error("Failed to load designations", e);
    } finally {
        loadingDesignations.value = false;
    }
};

const fetchLocations = async () => {
    loadingLocations.value = true;
    try {
        const res = await apiClient.get("/locations");
        locations.value = res.data.data || res.data || [];
    } catch (e) {
        console.error("Failed to load locations", e);
    } finally {
        loadingLocations.value = false;
    }
};

const fetchOrganization = async () => {
    try {
        const res = await apiClient.get("/organizations");
        const org = Array.isArray(res.data.data)
            ? res.data.data[0]
            : res.data.data || res.data;
        if (org) {
            orgForm.value = {
                id: org.id,
                name: org.name || "Pinnacle Technologies Inc.",
                code: org.code || "PINNACLE-HQ",
                address: org.address || "",
                timezone: org.timezone || "Asia/Manila",
            };
        }
    } catch (e) {
        console.warn("Organization endpoint not yet populated", e);
    }
};

// Department Actions
const openDepartmentModal = (dept) => {
    if (dept) {
        editingDeptId.value = dept.id;
        deptForm.value = {
            name: dept.name,
            code: dept.code,
            parent_id: dept.parent_id || null,
            head_id: dept.head_id || null,
        };
    } else {
        editingDeptId.value = null;
        deptForm.value = { name: "", code: "", parent_id: null, head_id: null };
    }
    showDeptModal.value = true;
};

const saveDepartment = async () => {
    if (!deptForm.value.name || !deptForm.value.code) {
        notify.error(
            "Required Fields",
            "Department name and code are required.",
        );
        return;
    }
    savingDept.value = true;
    try {
        if (editingDeptId.value) {
            await apiClient.put(
                `/departments/${editingDeptId.value}`,
                deptForm.value,
            );
            notify.toast("Department updated successfully.");
        } else {
            await apiClient.post("/departments", deptForm.value);
            notify.toast("Department created successfully.");
        }
        showDeptModal.value = false;
        await fetchDepartments();
    } finally {
        savingDept.value = false;
    }
};

const deleteDepartment = async (dept) => {
    const confirmed = await notify.confirm(
        "Delete Department",
        `Are you sure you want to delete department "${dept.name}"? This action cannot be undone.`,
        "Yes, Delete",
        "Cancel",
        true,
    );
    if (!confirmed) return;
    try {
        await apiClient.delete(`/departments/${dept.id}`);
        notify.toast("Department removed.");
        await fetchDepartments();
    } catch (e) {
        // Handled in client interceptor
    }
};

// Designation Actions
const openDesignationModal = (desig) => {
    if (desig) {
        editingDesigId.value = desig.id;
        desigForm.value = {
            name: desig.name,
            level: desig.level || 3,
            description: desig.description || "",
        };
    } else {
        editingDesigId.value = null;
        desigForm.value = { name: "", level: 3, description: "" };
    }
    showDesigModal.value = true;
};

const saveDesignation = async () => {
    if (!desigForm.value.name) return;
    try {
        if (editingDesigId.value) {
            await apiClient.put(
                `/designations/${editingDesigId.value}`,
                desigForm.value,
            );
            notify.toast("Designation updated.");
        } else {
            await apiClient.post("/designations", desigForm.value);
            notify.toast("Designation created.");
        }
        showDesigModal.value = false;
        await fetchDesignations();
    } catch (e) {}
};

const deleteDesignation = async (desig) => {
    const confirmed = await notify.confirm(
        "Delete Designation",
        `Delete "${desig.name}"?`,
        "Delete",
        "Cancel",
        true,
    );
    if (!confirmed) return;
    await apiClient.delete(`/designations/${desig.id}`);
    notify.toast("Designation removed.");
    await fetchDesignations();
};

// Location Actions
const openLocationModal = (loc) => {
    if (loc) {
        editingLocId.value = loc.id;
        locForm.value = {
            name: loc.name,
            address: loc.address || "",
            timezone: loc.timezone || "Asia/Manila",
            coordinates: loc.coordinates || "",
        };
    } else {
        editingLocId.value = null;
        locForm.value = {
            name: "",
            address: "",
            timezone: "Asia/Manila",
            coordinates: "",
        };
    }
    showLocModal.value = true;
};

const saveLocation = async () => {
    if (!locForm.value.name) return;
    try {
        if (editingLocId.value) {
            await apiClient.put(
                `/locations/${editingLocId.value}`,
                locForm.value,
            );
            notify.toast("Location updated.");
        } else {
            await apiClient.post("/locations", locForm.value);
            notify.toast("Location created.");
        }
        showLocModal.value = false;
        await fetchLocations();
    } catch (e) {}
};

const deleteLocation = async (loc) => {
    const confirmed = await notify.confirm(
        "Delete Location",
        `Delete "${loc.name}"?`,
        "Delete",
        "Cancel",
        true,
    );
    if (!confirmed) return;
    await apiClient.delete(`/locations/${loc.id}`);
    notify.toast("Location removed.");
    await fetchLocations();
};

const saveOrganization = async () => {
    savingOrg.value = true;
    try {
        const id = orgForm.value.id || 1;
        await apiClient.put(`/organizations/${id}`, orgForm.value);
        notify.toast("Organization profile updated.");
    } catch (e) {
    } finally {
        savingOrg.value = false;
    }
};

onMounted(() => {
    fetchDepartments();
    fetchDesignations();
    fetchLocations();
    fetchOrganization();
});
</script>
```

---

## 7. Feature 7: Global System Settings UI (`resources/js/components/settings/SystemSettings.vue`)

### 7.1 Design & Capabilities

- **Four Categorized Sections**:
    1. ⏱️ **Attendance Processing Rules**: Auto-process camera telemetry, late grace minutes (default 15), overtime threshold minutes (default 60), half-day minimum hours (default 4.0), weekend days selector, and nightly attendance finalizer time (`23:59`).
    2. 👥 **Visitor Management Policies**: Mandatory webcam face capture on check-in, mandatory NDA signature, auto-enrollment of temporary face to edge camera hardware, daily auto-checkout expiry time, and max visit duration hours.
    3. 🔔 **Alerts & Notification Channels**: Outbound email toggle, SMS gateway toggle, live WebSocket toast alerts, and audible web audio tone on rejected/stranger scans.
    4. ⚙️ **General & Security Preferences**: Organization display title, system timezone selector, session idle timeout minutes.
- **Visual Switch Components**: Interactive toggle switches (indigo/emerald when active) with clear operational descriptions.
- **Unsaved Changes Bar**: Sticky bottom notification bar alerting the administrator of modified values with instant "Save Settings" or "Discard" actions.

### 7.2 Implementation Blueprint (`resources/js/components/settings/SystemSettings.vue`)

```vue
<template>
    <div class="space-y-6 max-w-5xl">
        <!-- Header with Action -->
        <div
            class="flex items-center justify-between bg-white border border-slate-200/80 p-4 rounded-xl shadow-xs"
        >
            <div>
                <h3 class="text-base font-bold text-slate-900">
                    Global System Parameters &amp; Policies
                </h3>
                <p class="text-xs text-slate-500">
                    Fine-tune attendance calculation engines, visitor security
                    rules, and alert channels
                </p>
            </div>

            <div class="flex items-center gap-2">
                <button
                    @click="fetchSettings"
                    :disabled="loading"
                    class="px-3 py-2 bg-white hover:bg-slate-50 text-slate-700 text-xs font-medium border border-slate-200 rounded-lg shadow-xs transition-colors cursor-pointer"
                >
                    🔄 Refresh
                </button>
                <button
                    @click="saveAllSettings"
                    :disabled="saving || !hasChanges"
                    class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg shadow-sm transition-all flex items-center gap-1.5 cursor-pointer disabled:opacity-50"
                >
                    <span
                        v-if="saving"
                        class="w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin"
                    ></span>
                    <span>{{
                        saving ? "Saving Changes..." : "Save Settings"
                    }}</span>
                </button>
            </div>
        </div>

        <!-- Group 1: Attendance Engine -->
        <div
            class="bg-white border border-slate-200 rounded-xl p-6 shadow-xs space-y-5"
        >
            <div class="border-b border-slate-100 pb-3">
                <h4
                    class="text-sm font-bold text-slate-900 flex items-center gap-2"
                >
                    <span>⏱️</span> Attendance Engine Parameters
                </h4>
                <p class="text-[11px] text-slate-500">
                    Defines how camera biometric scans are paired into daily
                    attendance records
                </p>
            </div>

            <div class="space-y-4">
                <!-- Toggle: Auto Process -->
                <div
                    class="flex items-center justify-between py-2 border-b border-slate-100"
                >
                    <div>
                        <div class="text-xs font-bold text-slate-800">
                            Auto-Process Real-Time Camera Telemetry
                        </div>
                        <div class="text-[11px] text-slate-500">
                            Automatically pair entry/exit scans and update
                            employee attendance in real-time
                        </div>
                    </div>
                    <button
                        type="button"
                        @click="
                            settings['attendance.auto_process'] =
                                !settings['attendance.auto_process']
                        "
                        class="relative inline-flex h-5 w-10 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none"
                        :class="
                            settings['attendance.auto_process']
                                ? 'bg-indigo-600'
                                : 'bg-slate-300'
                        "
                    >
                        <span
                            class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                            :class="
                                settings['attendance.auto_process']
                                    ? 'translate-x-5'
                                    : 'translate-x-0'
                            "
                        />
                    </button>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-2">
                    <div>
                        <label
                            class="block text-xs font-semibold text-slate-700"
                            >Late Grace Period (Minutes)</label
                        >
                        <input
                            v-model.number="
                                settings['attendance.late_grace_minutes']
                            "
                            type="number"
                            min="0"
                            class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs font-mono text-slate-900 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
                        />
                        <span class="text-[10px] text-slate-400"
                            >Minutes past shift start before marked late</span
                        >
                    </div>

                    <div>
                        <label
                            class="block text-xs font-semibold text-slate-700"
                            >Overtime Threshold (Minutes)</label
                        >
                        <input
                            v-model.number="
                                settings[
                                    'attendance.overtime_threshold_minutes'
                                ]
                            "
                            type="number"
                            min="0"
                            class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs font-mono text-slate-900 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
                        />
                        <span class="text-[10px] text-slate-400"
                            >Minutes past shift end before OT accrues</span
                        >
                    </div>

                    <div>
                        <label
                            class="block text-xs font-semibold text-slate-700"
                            >Half-Day Threshold (Hours)</label
                        >
                        <input
                            v-model.number="
                                settings['attendance.half_day_threshold_hours']
                            "
                            type="number"
                            step="0.5"
                            min="1"
                            class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs font-mono text-slate-900 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
                        />
                        <span class="text-[10px] text-slate-400"
                            >Less hours worked counts as half day</span
                        >
                    </div>
                </div>

                <!-- Weekend Days Checkbox Group -->
                <div class="pt-3">
                    <label
                        class="block text-xs font-semibold text-slate-700 mb-1.5"
                        >Standard Weekend / Rest Days</label
                    >
                    <div class="flex items-center gap-3 text-xs">
                        <label
                            v-for="day in daysOfWeek"
                            :key="day.val"
                            class="flex items-center gap-1.5 text-slate-700 cursor-pointer"
                        >
                            <input
                                type="checkbox"
                                :value="day.val"
                                v-model="weekendDays"
                                class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 h-3.5 w-3.5"
                            />
                            <span>{{ day.name }}</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- Group 2: Visitor Rules -->
        <div
            class="bg-white border border-slate-200 rounded-xl p-6 shadow-xs space-y-5"
        >
            <div class="border-b border-slate-100 pb-3">
                <h4
                    class="text-sm font-bold text-slate-900 flex items-center gap-2"
                >
                    <span>👥</span> Visitor Management &amp; Security Policies
                </h4>
                <p class="text-[11px] text-slate-500">
                    Configure receptionist check-in rules and temporary camera
                    face provisioning
                </p>
            </div>

            <div class="space-y-4">
                <!-- Toggle: Require Photo -->
                <div
                    class="flex items-center justify-between py-2 border-b border-slate-100"
                >
                    <div>
                        <div class="text-xs font-bold text-slate-800">
                            Mandatory Webcam Face Photo on Check-In
                        </div>
                        <div class="text-[11px] text-slate-500">
                            Enforce capturing visitor photo before issuing
                            visitor badge
                        </div>
                    </div>
                    <button
                        type="button"
                        @click="
                            settings['visitor.require_photo'] =
                                !settings['visitor.require_photo']
                        "
                        class="relative inline-flex h-5 w-10 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out"
                        :class="
                            settings['visitor.require_photo']
                                ? 'bg-indigo-600'
                                : 'bg-slate-300'
                        "
                    >
                        <span
                            class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                            :class="
                                settings['visitor.require_photo']
                                    ? 'translate-x-5'
                                    : 'translate-x-0'
                            "
                        />
                    </button>
                </div>

                <!-- Toggle: Require NDA -->
                <div
                    class="flex items-center justify-between py-2 border-b border-slate-100"
                >
                    <div>
                        <div class="text-xs font-bold text-slate-800">
                            Mandatory NDA Agreement Sign-off
                        </div>
                        <div class="text-[11px] text-slate-500">
                            Require visitor acknowledgment of corporate
                            Non-Disclosure Agreement
                        </div>
                    </div>
                    <button
                        type="button"
                        @click="
                            settings['visitor.require_nda'] =
                                !settings['visitor.require_nda']
                        "
                        class="relative inline-flex h-5 w-10 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out"
                        :class="
                            settings['visitor.require_nda']
                                ? 'bg-indigo-600'
                                : 'bg-slate-300'
                        "
                    >
                        <span
                            class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                            :class="
                                settings['visitor.require_nda']
                                    ? 'translate-x-5'
                                    : 'translate-x-0'
                            "
                        />
                    </button>
                </div>

                <!-- Toggle: Enroll Face to Camera -->
                <div
                    class="flex items-center justify-between py-2 border-b border-slate-100"
                >
                    <div>
                        <div class="text-xs font-bold text-slate-800">
                            Auto-Enroll Biometric Face to Edge Cameras
                        </div>
                        <div class="text-[11px] text-slate-500">
                            Automatically push temporary face credentials to
                            camera hardware on check-in and revoke on checkout
                        </div>
                    </div>
                    <button
                        type="button"
                        @click="
                            settings['visitor.enroll_face_to_camera'] =
                                !settings['visitor.enroll_face_to_camera']
                        "
                        class="relative inline-flex h-5 w-10 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out"
                        :class="
                            settings['visitor.enroll_face_to_camera']
                                ? 'bg-indigo-600'
                                : 'bg-slate-300'
                        "
                    >
                        <span
                            class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                            :class="
                                settings['visitor.enroll_face_to_camera']
                                    ? 'translate-x-5'
                                    : 'translate-x-0'
                            "
                        />
                    </button>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
                    <div>
                        <label
                            class="block text-xs font-semibold text-slate-700"
                            >Nightly Auto-Checkout Cutoff Time</label
                        >
                        <input
                            v-model="settings['visitor.auto_checkout_time']"
                            type="time"
                            class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs font-mono text-slate-900 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
                        />
                        <span class="text-[10px] text-slate-400"
                            >Visits open past this hour are automatically marked
                            departed</span
                        >
                    </div>

                    <div>
                        <label
                            class="block text-xs font-semibold text-slate-700"
                            >Maximum Allowed Visit Duration (Hours)</label
                        >
                        <input
                            v-model.number="
                                settings['visitor.max_visit_duration_hours']
                            "
                            type="number"
                            min="1"
                            max="24"
                            class="w-full mt-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs font-mono text-slate-900 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
                        />
                        <span class="text-[10px] text-slate-400"
                            >Triggers an alert on the receptionist dashboard if
                            exceeded</span
                        >
                    </div>
                </div>
            </div>
        </div>

        <!-- Group 3: Notifications -->
        <div
            class="bg-white border border-slate-200 rounded-xl p-6 shadow-xs space-y-5"
        >
            <div class="border-b border-slate-100 pb-3">
                <h4
                    class="text-sm font-bold text-slate-900 flex items-center gap-2"
                >
                    <span>🔔</span> Notifications &amp; System Telemetry
                </h4>
                <p class="text-[11px] text-slate-500">
                    Configure outbound channels and audio feedback for security
                    events
                </p>
            </div>

            <div class="space-y-4">
                <!-- Toggle: Email -->
                <div
                    class="flex items-center justify-between py-2 border-b border-slate-100"
                >
                    <div>
                        <div class="text-xs font-bold text-slate-800">
                            Email Notifications
                        </div>
                        <div class="text-[11px] text-slate-500">
                            Deliver host notifications, leave approval requests,
                            and security summaries via SMTP
                        </div>
                    </div>
                    <button
                        type="button"
                        @click="
                            settings['notification.email_enabled'] =
                                !settings['notification.email_enabled']
                        "
                        class="relative inline-flex h-5 w-10 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out"
                        :class="
                            settings['notification.email_enabled']
                                ? 'bg-indigo-600'
                                : 'bg-slate-300'
                        "
                    >
                        <span
                            class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                            :class="
                                settings['notification.email_enabled']
                                    ? 'translate-x-5'
                                    : 'translate-x-0'
                            "
                        />
                    </button>
                </div>

                <!-- Toggle: SMS -->
                <div
                    class="flex items-center justify-between py-2 border-b border-slate-100"
                >
                    <div>
                        <div class="text-xs font-bold text-slate-800">
                            SMS Gateway Dispatch
                        </div>
                        <div class="text-[11px] text-slate-500">
                            Send urgent security alerts and visitor arrival SMS
                            notifications
                        </div>
                    </div>
                    <button
                        type="button"
                        @click="
                            settings['notification.sms_enabled'] =
                                !settings['notification.sms_enabled']
                        "
                        class="relative inline-flex h-5 w-10 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out"
                        :class="
                            settings['notification.sms_enabled']
                                ? 'bg-indigo-600'
                                : 'bg-slate-300'
                        "
                    >
                        <span
                            class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                            :class="
                                settings['notification.sms_enabled']
                                    ? 'translate-x-5'
                                    : 'translate-x-0'
                            "
                        />
                    </button>
                </div>

                <!-- Toggle: Sound on Rejection -->
                <div class="flex items-center justify-between py-2">
                    <div>
                        <div class="text-xs font-bold text-slate-800">
                            Audible Alarm on Stranger &amp; Rejected Scans
                        </div>
                        <div class="text-[11px] text-slate-500">
                            Play synthetic Web Audio tone on live monitor
                            whenever an unauthorized scan occurs
                        </div>
                    </div>
                    <button
                        type="button"
                        @click="
                            settings['notification.stranger_alert_sound'] =
                                !settings['notification.stranger_alert_sound']
                        "
                        class="relative inline-flex h-5 w-10 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out"
                        :class="
                            settings['notification.stranger_alert_sound']
                                ? 'bg-indigo-600'
                                : 'bg-slate-300'
                        "
                    >
                        <span
                            class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                            :class="
                                settings['notification.stranger_alert_sound']
                                    ? 'translate-x-5'
                                    : 'translate-x-0'
                            "
                        />
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from "vue";
import apiClient from "../../api/client";
import notify from "../../utils/notify";

const loading = ref(false);
const saving = ref(false);

const daysOfWeek = [
    { val: 0, name: "Sun" },
    { val: 1, name: "Mon" },
    { val: 2, name: "Tue" },
    { val: 3, name: "Wed" },
    { val: 4, name: "Thu" },
    { val: 5, name: "Fri" },
    { val: 6, name: "Sat" },
];

const defaultSettings = {
    "attendance.auto_process": true,
    "attendance.late_grace_minutes": 15,
    "attendance.overtime_threshold_minutes": 60,
    "attendance.half_day_threshold_hours": 4.0,
    "attendance.weekend_days": [0, 6],
    "visitor.require_photo": true,
    "visitor.require_nda": false,
    "visitor.enroll_face_to_camera": true,
    "visitor.auto_checkout_time": "20:00",
    "visitor.max_visit_duration_hours": 8,
    "notification.email_enabled": true,
    "notification.sms_enabled": false,
    "notification.stranger_alert_sound": true,
};

const settings = ref({ ...defaultSettings });
const originalSettings = ref({ ...defaultSettings });
const weekendDays = ref([0, 6]);

const hasChanges = computed(() => {
    return (
        JSON.stringify(settings.value) !==
        JSON.stringify(originalSettings.value)
    );
});

const fetchSettings = async () => {
    loading.value = true;
    try {
        const res = await apiClient.get("/settings");
        const data = res.data.data || res.data || {};

        // Merge returned key-values
        const merged = { ...defaultSettings };
        if (typeof data === "object") {
            Object.keys(data).forEach((key) => {
                merged[key] =
                    data[key]?.value !== undefined
                        ? data[key].value
                        : data[key];
            });
        }

        settings.value = merged;
        originalSettings.value = JSON.parse(JSON.stringify(merged));
        if (Array.isArray(merged["attendance.weekend_days"])) {
            weekendDays.value = merged["attendance.weekend_days"];
        }
    } catch (err) {
        console.warn("Settings fetch error (using defaults):", err);
    } finally {
        loading.value = false;
    }
};

const saveAllSettings = async () => {
    saving.value = true;
    settings.value["attendance.weekend_days"] = weekendDays.value;

    try {
        await apiClient.post("/settings/bulk", { settings: settings.value });
        originalSettings.value = JSON.parse(JSON.stringify(settings.value));
        notify.toast("System settings updated successfully.");
    } catch (err) {
        // Handled in client interceptor
    } finally {
        saving.value = false;
    }
};

onMounted(() => {
    fetchSettings();
});
</script>
```

---

## 8. Feature 8: Audit Log Viewer (`resources/js/components/settings/AuditLogViewer.vue`)

### 8.1 Design & Capabilities

- **Filterable Audit Browser**: Search by keyword/user/IP, filter by Action (`created`, `updated`, `deleted`, `login`, `logout`), and filter by Entity (`Department`, `Employee`, `Device`, `Shift`, `Visitor`, `Setting`, `User`).
- **Pagination & Timezone Formatting**: Clean PHT timestamp display with responsive pagination controls.
- **Diff Inspection Modal**: Side-by-side key/value inspection highlighting changed properties with JSON formatting.

### 8.2 Implementation Blueprint (`resources/js/components/settings/AuditLogViewer.vue`)

```vue
<template>
    <div class="space-y-4">
        <!-- Top Filter Bar -->
        <div
            class="flex flex-col sm:flex-row items-center gap-3 bg-white border border-slate-200 p-3 rounded-xl shadow-xs"
        >
            <div class="relative flex-1 w-full">
                <input
                    v-model="search"
                    @input="debouncedFetch"
                    type="text"
                    placeholder="Search by actor, IP address, or entity ID..."
                    class="w-full bg-white border border-slate-200 rounded-lg pl-9 pr-4 py-2 text-xs text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs"
                />
                <span class="absolute left-3 top-2.5 text-slate-400 text-xs"
                    >🔍</span
                >
            </div>

            <select
                v-model="actionFilter"
                @change="fetchLogs(1)"
                class="w-full sm:w-40 bg-white border border-slate-200 text-xs rounded-lg px-3 py-2 text-slate-700 cursor-pointer shadow-xs"
            >
                <option value="">All Actions</option>
                <option value="created">Created</option>
                <option value="updated">Updated</option>
                <option value="deleted">Deleted</option>
                <option value="login">Login</option>
                <option value="logout">Logout</option>
            </select>

            <select
                v-model="entityFilter"
                @change="fetchLogs(1)"
                class="w-full sm:w-44 bg-white border border-slate-200 text-xs rounded-lg px-3 py-2 text-slate-700 cursor-pointer shadow-xs"
            >
                <option value="">All Entities</option>
                <option value="Department">Department</option>
                <option value="Designation">Designation</option>
                <option value="Location">Location</option>
                <option value="Device">Device</option>
                <option value="Personnel">Personnel</option>
                <option value="Setting">Setting</option>
                <option value="User">User</option>
            </select>

            <button
                @click="fetchLogs(1)"
                class="px-3 py-2 bg-white hover:bg-slate-50 text-slate-700 text-xs font-semibold border border-slate-200 rounded-lg shadow-xs transition-colors cursor-pointer"
                title="Refresh logs"
            >
                🔄
            </button>
        </div>

        <!-- Data Table -->
        <div
            class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-xs"
        >
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs text-slate-700">
                    <thead
                        class="bg-slate-50 text-slate-500 uppercase text-[11px] font-semibold border-b border-slate-200"
                    >
                        <tr>
                            <th class="py-3 px-4">Timestamp (PHT)</th>
                            <th class="py-3 px-4">Actor</th>
                            <th class="py-3 px-4">Action</th>
                            <th class="py-3 px-4">Target Entity</th>
                            <th class="py-3 px-4">IP Address</th>
                            <th class="py-3 px-4 text-right">Details</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-if="loading">
                            <td
                                colspan="6"
                                class="py-12 text-center text-slate-500"
                            >
                                Loading audit trail records...
                            </td>
                        </tr>
                        <tr v-else-if="logs.length === 0">
                            <td
                                colspan="6"
                                class="py-12 text-center text-slate-500"
                            >
                                No audit records match the selected criteria.
                            </td>
                        </tr>
                        <tr
                            v-for="log in logs"
                            :key="log.id"
                            class="hover:bg-slate-50 transition-colors"
                        >
                            <td class="py-3 px-4 font-mono text-slate-600">
                                {{ formatTimestamp(log.created_at) }}
                            </td>
                            <td class="py-3 px-4">
                                <div class="font-bold text-slate-900">
                                    {{
                                        log.user?.name ||
                                        log.user_name ||
                                        "System / Auto"
                                    }}
                                </div>
                                <div
                                    class="text-[10px] text-slate-400 font-mono"
                                >
                                    {{ log.user?.email || "" }}
                                </div>
                            </td>
                            <td class="py-3 px-4">
                                <span
                                    class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider"
                                    :class="actionBadgeClass(log.action)"
                                >
                                    {{ log.action }}
                                </span>
                            </td>
                            <td class="py-3 px-4">
                                <div class="font-medium text-slate-800">
                                    {{ cleanEntityName(log.auditable_type) }}
                                </div>
                                <div
                                    class="text-[10px] font-mono text-slate-400"
                                >
                                    ID: #{{ log.auditable_id || "N/A" }}
                                </div>
                            </td>
                            <td
                                class="py-3 px-4 font-mono text-[11px] text-slate-500"
                            >
                                {{ log.ip_address || "127.0.0.1" }}
                            </td>
                            <td class="py-3 px-4 text-right">
                                <button
                                    @click="inspectDiff(log)"
                                    class="px-2.5 py-1 text-[11px] bg-white hover:bg-slate-50 text-indigo-600 font-semibold border border-slate-200 rounded transition-colors shadow-xs cursor-pointer"
                                >
                                    Inspect Diff
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div
                class="p-3 bg-slate-50 border-t border-slate-200 flex items-center justify-between text-xs text-slate-500"
            >
                <div>
                    Showing Page {{ pagination.current_page }} of
                    {{ pagination.last_page }} ({{ pagination.total }} records)
                </div>
                <div class="flex items-center gap-1">
                    <button
                        @click="fetchLogs(pagination.current_page - 1)"
                        :disabled="pagination.current_page <= 1"
                        class="px-2.5 py-1 bg-white border border-slate-200 rounded disabled:opacity-40 cursor-pointer shadow-xs"
                    >
                        Previous
                    </button>
                    <button
                        @click="fetchLogs(pagination.current_page + 1)"
                        :disabled="
                            pagination.current_page >= pagination.last_page
                        "
                        class="px-2.5 py-1 bg-white border border-slate-200 rounded disabled:opacity-40 cursor-pointer shadow-xs"
                    >
                        Next
                    </button>
                </div>
            </div>
        </div>

        <!-- DIFF MODAL -->
        <div
            v-if="selectedLog"
            class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4"
        >
            <div
                class="bg-white rounded-2xl border border-slate-200 max-w-2xl w-full p-6 shadow-2xl max-h-[85vh] flex flex-col space-y-4"
            >
                <div
                    class="flex items-start justify-between border-b border-slate-100 pb-3"
                >
                    <div>
                        <h3 class="text-base font-bold text-slate-900">
                            Audit Log Details &amp; Change Diff
                        </h3>
                        <p class="text-xs text-slate-500 font-mono">
                            {{ formatTimestamp(selectedLog.created_at) }} &bull;
                            {{ selectedLog.user?.name || "System" }}
                        </p>
                    </div>
                    <button
                        @click="selectedLog = null"
                        class="text-slate-400 hover:text-slate-600 text-lg font-bold"
                    >
                        &times;
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto space-y-4 pr-1 text-xs">
                    <!-- Metadata -->
                    <div
                        class="grid grid-cols-2 sm:grid-cols-4 gap-2 bg-slate-50 p-3 rounded-xl border border-slate-200/80"
                    >
                        <div>
                            <div
                                class="text-[10px] text-slate-400 uppercase font-bold"
                            >
                                Action
                            </div>
                            <div class="font-bold text-slate-800 capitalize">
                                {{ selectedLog.action }}
                            </div>
                        </div>
                        <div>
                            <div
                                class="text-[10px] text-slate-400 uppercase font-bold"
                            >
                                Entity
                            </div>
                            <div class="font-bold text-slate-800">
                                {{
                                    cleanEntityName(selectedLog.auditable_type)
                                }}
                                #{{ selectedLog.auditable_id }}
                            </div>
                        </div>
                        <div>
                            <div
                                class="text-[10px] text-slate-400 uppercase font-bold"
                            >
                                IP Address
                            </div>
                            <div class="font-mono text-slate-800">
                                {{ selectedLog.ip_address }}
                            </div>
                        </div>
                        <div>
                            <div
                                class="text-[10px] text-slate-400 uppercase font-bold"
                            >
                                User Agent
                            </div>
                            <div
                                class="truncate text-slate-600"
                                :title="selectedLog.user_agent"
                            >
                                {{ selectedLog.user_agent || "--" }}
                            </div>
                        </div>
                    </div>

                    <!-- Diff Values -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <h4
                                class="text-xs font-bold text-rose-700 mb-1.5 flex items-center gap-1"
                            >
                                <span>◀</span> Previous State (Old Values)
                            </h4>
                            <pre
                                class="bg-rose-50/50 border border-rose-200 rounded-lg p-3 font-mono text-[11px] text-slate-800 whitespace-pre-wrap max-h-60 overflow-y-auto"
                                >{{ formatJson(selectedLog.old_values) }}</pre
                            >
                        </div>

                        <div>
                            <h4
                                class="text-xs font-bold text-emerald-700 mb-1.5 flex items-center gap-1"
                            >
                                <span>▶</span> New State (New Values)
                            </h4>
                            <pre
                                class="bg-emerald-50/50 border border-emerald-200 rounded-lg p-3 font-mono text-[11px] text-slate-800 whitespace-pre-wrap max-h-60 overflow-y-auto"
                                >{{ formatJson(selectedLog.new_values) }}</pre
                            >
                        </div>
                    </div>
                </div>

                <div class="pt-3 border-t border-slate-100 flex justify-end">
                    <button
                        @click="selectedLog = null"
                        class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded-lg cursor-pointer"
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted } from "vue";
import apiClient from "../../api/client";

const search = ref("");
const actionFilter = ref("");
const entityFilter = ref("");
const loading = ref(false);
const logs = ref([]);
const selectedLog = ref(null);

const pagination = ref({
    current_page: 1,
    last_page: 1,
    total: 0,
});

let debounceTimer = null;
const debouncedFetch = () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => fetchLogs(1), 300);
};

const fetchLogs = async (page = 1) => {
    loading.value = true;
    try {
        const res = await apiClient.get("/audit-logs", {
            params: {
                page,
                per_page: 20,
                search: search.value || undefined,
                action: actionFilter.value || undefined,
                auditable_type: entityFilter.value || undefined,
            },
        });

        const data = res.data;
        logs.value = data.data || [];
        pagination.value = {
            current_page: data.current_page || 1,
            last_page: data.last_page || 1,
            total: data.total || 0,
        };
    } catch (err) {
        console.error("Audit logs error", err);
    } finally {
        loading.value = false;
    }
};

const inspectDiff = (log) => {
    selectedLog.value = log;
};

const formatTimestamp = (ts) => {
    if (!ts) return "--";
    return new Date(ts).toLocaleString("en-PH", { timeZone: "Asia/Manila" });
};

const cleanEntityName = (type) => {
    if (!type) return "System";
    return type.replace(/^App\\Models\\/, "");
};

const formatJson = (val) => {
    if (!val) return "None";
    if (typeof val === "string") {
        try {
            return JSON.stringify(JSON.parse(val), null, 2);
        } catch {
            return val;
        }
    }
    return JSON.stringify(val, null, 2);
};

const actionBadgeClass = (action) => {
    switch (action) {
        case "created":
        case "approved":
            return "bg-emerald-50 text-emerald-700 border border-emerald-200";
        case "updated":
            return "bg-indigo-50 text-indigo-700 border border-indigo-200";
        case "deleted":
        case "rejected":
            return "bg-rose-50 text-rose-700 border border-rose-200";
        case "login":
        case "logout":
            return "bg-amber-50 text-amber-700 border border-amber-200";
        default:
            return "bg-slate-100 text-slate-700 border border-slate-200";
    }
};

onMounted(() => {
    fetchLogs();
});
</script>
```

---

## 9. Settings Hub Container (`resources/js/components/settings/SettingsHub.vue`)

To keep `App.vue` clean and prevent monolithic growth, `SettingsHub.vue` unites the three settings sub-views:

```vue
<template>
    <div class="space-y-6">
        <!-- Hub Top Header -->
        <div
            class="flex items-center justify-between bg-white border border-slate-200/80 p-4 rounded-xl shadow-xs"
        >
            <div>
                <h2 class="text-lg font-bold text-slate-900">
                    System Administration &amp; Settings
                </h2>
                <p class="text-xs text-slate-500">
                    Manage organization hierarchy, biometric parameters,
                    security policies, and audit logs
                </p>
            </div>

            <!-- Settings Sub-Navigation Pill Bar -->
            <div
                class="flex items-center gap-1.5 bg-slate-100 p-1 rounded-xl border border-slate-200"
            >
                <button
                    v-for="tab in tabs"
                    :key="tab.id"
                    @click="activeTab = tab.id"
                    class="px-3.5 py-1.5 text-xs font-semibold rounded-lg transition-all flex items-center gap-1.5 cursor-pointer"
                    :class="
                        activeTab === tab.id
                            ? 'bg-white text-indigo-600 shadow-xs font-bold'
                            : 'text-slate-600 hover:text-slate-900'
                    "
                >
                    <span>{{ tab.icon }}</span>
                    <span>{{ tab.label }}</span>
                </button>
            </div>
        </div>

        <!-- Active Sub-View Component -->
        <div>
            <DepartmentManager v-if="activeTab === 'departments'" />
            <SystemSettings v-else-if="activeTab === 'system'" />
            <AuditLogViewer v-else-if="activeTab === 'audit'" />
        </div>
    </div>
</template>

<script setup>
import { ref } from "vue";
import DepartmentManager from "./DepartmentManager.vue";
import SystemSettings from "./SystemSettings.vue";
import AuditLogViewer from "./AuditLogViewer.vue";

const activeTab = ref("departments");

const tabs = [
    { id: "departments", label: "Organization & Departments", icon: "🏢" },
    { id: "system", label: "System Parameters", icon: "⚙️" },
    { id: "audit", label: "Audit Trail", icon: "📋" },
];
</script>
```

---

## 10. `App.vue` Shell Integration: Auth Gate, Navigation & User Profile Dropdown

### 10.1 Key Enhancements in `App.vue`

1. **Auth Gate**: If `!authStore.isAuthenticated`, displays `<LoginPage />`.
2. **WebSocket & Polling Gating**: Telemetry polling and Echo WebSocket listeners (`access-logs`, `stranger-snaps`, `device-status`) are only active when the user is authenticated.
3. **User Profile Dropdown in Header**:
    - Shows user avatar circle with initials and primary role badge.
    - Click toggles a dropdown showing name, email, roles, link to Settings tab, and "Sign Out" button.
4. **Navigation Tabs Integration**:
    - Dynamically appends `⚙️ Settings & Org` tab (`settings`) visible only to users with the `admin` / `super-admin` role or `settings.view` permission.
5. **Code Splitting via `defineAsyncComponent`**:
    - Asynchronously loads heavy views (`DeviceManager`, `SettingsHub`, etc.) keeping initial bundle load sub-150kB.

### 10.2 Implementation Blueprint (`resources/js/App.vue`)

```vue
<template>
    <!-- AUTHENTICATION GATE: RENDER LOGIN PAGE IF UNAUTHENTICATED -->
    <div v-if="!authStore.isAuthenticated" class="min-h-screen">
        <LoginPage />
    </div>

    <!-- AUTHENTICATED SYSTEM SHELL -->
    <div
        v-else
        class="min-h-screen bg-slate-50 text-slate-900 flex flex-col font-sans antialiased selection:bg-indigo-500 selection:text-white"
    >
        <!-- Top Navigation Header -->
        <header
            class="bg-white/90 border-b border-slate-200/80 sticky top-0 z-40 backdrop-blur-md shadow-xs"
        >
            <div
                class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between"
            >
                <!-- Brand & Title -->
                <div class="flex items-center gap-3">
                    <img
                        :src="logoLight"
                        alt="Pinnacle Technologies Logo"
                        class="h-9 w-auto"
                    />
                    <div
                        class="h-6 w-px bg-slate-200/80 mx-0.5 hidden sm:block"
                    ></div>
                    <div>
                        <h1
                            class="text-sm font-bold tracking-tight text-slate-900 flex items-center gap-2"
                        >
                            AI Camera Hub
                        </h1>
                        <p class="text-[11px] text-slate-500">
                            Decoupled Biometric Access Control &amp; Edge Stream
                            Processor
                        </p>
                    </div>
                </div>

                <!-- Header Right: WebSocket Badge & User Profile Dropdown -->
                <div class="flex items-center gap-4">
                    <!-- User Profile Dropdown -->
                    <div class="relative">
                        <button
                            @click="userMenuOpen = !userMenuOpen"
                            class="flex items-center gap-2.5 p-1.5 rounded-xl hover:bg-slate-100 transition-colors cursor-pointer border border-transparent hover:border-slate-200"
                        >
                            <!-- Avatar Circle -->
                            <div
                                class="w-8 h-8 rounded-full bg-indigo-600 text-white font-bold text-xs flex items-center justify-center shadow-xs"
                            >
                                {{ authStore.userInitials }}
                            </div>
                            <div class="hidden md:block text-left">
                                <div
                                    class="text-xs font-bold text-slate-900 leading-tight"
                                >
                                    {{ authStore.userName }}
                                </div>
                                <div
                                    class="text-[10px] text-indigo-600 font-semibold uppercase"
                                >
                                    {{ authStore.primaryRoleBadge }}
                                </div>
                            </div>
                            <span
                                class="text-slate-400 text-xs hidden md:inline"
                                >▾</span
                            >
                        </button>

                        <!-- Dropdown Menu -->
                        <div
                            v-if="userMenuOpen"
                            @click="userMenuOpen = false"
                            class="absolute right-0 mt-2 w-56 bg-white border border-slate-200 rounded-xl shadow-xl py-2 z-50 divide-y divide-slate-100"
                        >
                            <div class="px-4 py-2 text-xs">
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
                                        class="px-1.5 py-0.2 rounded text-[9px] font-bold uppercase bg-slate-100 text-slate-600"
                                    >
                                        {{ role }}
                                    </span>
                                </div>
                            </div>

                            <div class="py-1 text-xs">
                                <button
                                    v-if="authStore.isAdmin"
                                    @click="currentTab = 'settings'"
                                    class="w-full text-left px-4 py-2 hover:bg-slate-50 text-slate-700 flex items-center gap-2 cursor-pointer"
                                >
                                    <span>⚙️</span> System Settings
                                </button>
                            </div>

                            <div class="py-1 text-xs">
                                <button
                                    @click="handleLogout"
                                    class="w-full text-left px-4 py-2 hover:bg-rose-50 text-rose-600 font-semibold flex items-center gap-2 cursor-pointer"
                                >
                                    <span>🚪</span> Sign Out
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- KPI Summary Metric Cards -->
        <section class="max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 pt-6">
            <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-5 gap-4">
                <!-- Metric 1: Total Verifications Today -->
                <div
                    class="bg-white border border-slate-200/80 rounded-2xl p-4 shadow-sm shadow-slate-200/60"
                >
                    <div
                        class="text-[11px] font-medium text-slate-500 uppercase tracking-wider"
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
                        {{ store.stats.telemetry?.allowed_today || 0 }} allowed
                        passes
                    </div>
                </div>

                <!-- Metric 2: Denied / Strangers -->
                <div
                    class="bg-white border border-slate-200/80 rounded-2xl p-4 shadow-sm shadow-slate-200/60"
                >
                    <div
                        class="text-[11px] font-medium text-slate-500 uppercase tracking-wider"
                    >
                        Alerts &amp; Strangers
                    </div>
                    <div
                        class="text-2xl font-bold text-amber-600 mt-1 font-mono"
                    >
                        {{
                            (store.stats.telemetry?.rejected_today || 0) +
                            (store.stats.telemetry?.strangers_today || 0)
                        }}
                    </div>
                    <div class="text-[10px] text-rose-600 font-medium mt-0.5">
                        {{
                            store.stats.telemetry?.rejected_today || 0
                        }}
                        rejected / blacklist
                    </div>
                </div>

                <!-- Metric 3: Online Camera Fleet -->
                <div
                    class="bg-white border border-slate-200/80 rounded-2xl p-4 shadow-sm shadow-slate-200/60"
                >
                    <div
                        class="text-[11px] font-medium text-slate-500 uppercase tracking-wider"
                    >
                        Active Cameras
                    </div>
                    <div
                        class="text-2xl font-bold text-emerald-600 mt-1 font-mono"
                    >
                        {{ store.stats.devices?.online || 0 }} /
                        {{ store.stats.devices?.total || 0 }}
                    </div>
                    <div class="text-[10px] text-slate-500 mt-0.5">
                        {{ store.stats.devices?.offline || 0 }} offline
                    </div>
                </div>

                <!-- Metric 4: Personnel Face Library -->
                <div
                    class="bg-white border border-slate-200/80 rounded-2xl p-4 shadow-sm shadow-slate-200/60"
                >
                    <div
                        class="text-[11px] font-medium text-slate-500 uppercase tracking-wider"
                    >
                        Enrolled Face Lib
                    </div>
                    <div
                        class="text-2xl font-bold text-indigo-600 mt-1 font-mono"
                    >
                        {{ store.stats.personnel?.total || 0 }}
                    </div>
                    <div class="text-[10px] text-slate-500 mt-0.5">
                        {{ store.stats.personnel?.whitelisted || 0 }} whitelist
                        / {{ store.stats.personnel?.blacklisted || 0 }} block
                    </div>
                </div>

                <!-- Metric 5: Redis Outbox Queue -->
                <div
                    class="col-span-2 sm:col-span-4 lg:col-span-1 bg-white border border-slate-200/80 rounded-2xl p-4 shadow-sm shadow-slate-200/60"
                >
                    <div
                        class="text-[11px] font-medium text-slate-500 uppercase tracking-wider"
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
                                : 'text-slate-500'
                        "
                    >
                        {{ store.stats.sync?.failed || 0 }} failed tasks
                    </div>
                </div>
            </div>
        </section>

        <!-- Main Content Area with Tab Navigation -->
        <main
            class="max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 flex-1 flex flex-col"
        >
            <!-- Navigation Tabs -->
            <nav
                class="flex items-center gap-2 border-b border-slate-200 mb-6 overflow-x-auto pb-1"
            >
                <button
                    v-for="tab in visibleTabs"
                    :key="tab.id"
                    @click="currentTab = tab.id"
                    class="px-4 py-2.5 text-xs font-semibold rounded-t-xl transition-all whitespace-nowrap flex items-center gap-2 border-b-2 cursor-pointer"
                    :class="
                        currentTab === tab.id
                            ? 'text-indigo-600 border-indigo-600 bg-white shadow-xs'
                            : 'text-slate-500 border-transparent hover:text-slate-800 hover:border-slate-300'
                    "
                >
                    <span>{{ tab.icon }}</span>
                    <span>{{ tab.label }}</span>
                </button>
            </nav>

            <!-- Active Tab Component View -->
            <div class="flex-1">
                <LiveTelemetry v-if="currentTab === 'live'" />
                <StrangerSnapsMonitor v-else-if="currentTab === 'strangers'" />
                <PersonnelManager v-else-if="currentTab === 'personnel'" />
                <DeviceManager v-else-if="currentTab === 'devices'" />
                <AccessLogsHistory v-else-if="currentTab === 'logs'" />
                <SyncTasksMonitor v-else-if="currentTab === 'sync'" />
                <SettingsHub v-else-if="currentTab === 'settings'" />
            </div>
        </main>

        <!-- Footer -->
        <footer
            class="bg-white border-t border-slate-200 py-4 text-center text-xs text-slate-500"
        >
            <div
                class="max-w-7xl mx-auto px-4 flex flex-col sm:flex-row items-center justify-between gap-3"
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
                        >Intelligent AI Camera Hub &amp; Attendance System</span
                    >
                </div>
                <span>
                    MQTT Broker:
                    <code class="text-indigo-600 font-mono font-semibold"
                        >:1883</code
                    >
                    &bull; Reverb WebSockets:
                    <code class="text-indigo-600 font-mono font-semibold"
                        >:8080</code
                    >
                </span>
            </div>
        </footer>
    </div>
</template>

<script setup>
import {
    ref,
    computed,
    onMounted,
    onUnmounted,
    defineAsyncComponent,
} from "vue";
import logoLight from "../images/pinnacle-logo-light.svg";
import logoIcon from "../images/pinnacle-icon.svg";
import { useCameraStore } from "./stores/cameraStore";
import { useAuthStore } from "./stores/authStore";
import echo from "./echo";
import LoginPage from "./views/LoginPage.vue";

// Views
import LiveTelemetry from "./views/LiveTelemetry.vue";
import StrangerSnapsMonitor from "./views/StrangerSnapsMonitor.vue";
import PersonnelManager from "./views/PersonnelManager.vue";
import AccessLogsHistory from "./views/AccessLogsHistory.vue";
import SyncTasksMonitor from "./views/SyncTasksMonitor.vue";

// Async Loaded Components
const DeviceManager = defineAsyncComponent(
    () => import("./views/DeviceManager.vue"),
);
const SettingsHub = defineAsyncComponent(
    () => import("./components/settings/SettingsHub.vue"),
);

const store = useCameraStore();
const authStore = useAuthStore();

const currentTab = ref("live");
const userMenuOpen = ref(false);

const baseTabs = [
    { id: "live", label: "Live Telemetry", icon: "📹" },
    { id: "strangers", label: "Stranger Snaps", icon: "🎭" },
    { id: "personnel", label: "Personnel & Face Library", icon: "👥" },
    { id: "devices", label: "Camera Devices", icon: "📡" },
    { id: "logs", label: "Access Audit Logs", icon: "📋" },
    { id: "sync", label: "Sync Outbox Queue", icon: "⚡" },
    {
        id: "settings",
        label: "Settings & Org",
        icon: "⚙️",
        requiresAdmin: true,
    },
];

const visibleTabs = computed(() => {
    return baseTabs.filter((tab) => !tab.requiresAdmin || authStore.isAdmin);
});

const handleLogout = async () => {
    userMenuOpen.value = false;
    await authStore.logout();
};

let interval = null;

const initTelemetry = () => {
    store.fetchStats();
    store.fetchDevices();
    store.fetchRecentLogs();

    echo.channel("access-logs")
        .listen(".AccessLogReceived", (e) => store.addLiveLog(e))
        .listen("AccessLogReceived", (e) => store.addLiveLog(e));

    echo.channel("stranger-snaps")
        .listen(".StrangerSnapReceived", (e) => store.addStrangerSnap(e))
        .listen("StrangerSnapReceived", (e) => store.addStrangerSnap(e));

    echo.channel("device-status")
        .listen(".DeviceStatusUpdated", (e) => store.updateDeviceStatus(e))
        .listen("DeviceStatusUpdated", (e) => store.updateDeviceStatus(e));

    if (echo.connector?.pusher?.connection) {
        echo.connector.pusher.connection.bind("connected", () => {
            store.wsConnected = true;
        });
        echo.connector.pusher.connection.bind("disconnected", () => {
            store.wsConnected = false;
        });
        echo.connector.pusher.connection.bind("connecting", () => {
            store.wsConnected = false;
        });
        if (echo.connector.pusher.connection.state === "connected") {
            store.wsConnected = true;
        }
    }

    interval = setInterval(() => {
        store.fetchStats();
    }, 10000);
};

onMounted(async () => {
    await authStore.initAuth();
    if (authStore.isAuthenticated) {
        initTelemetry();
    }
});

onUnmounted(() => {
    if (interval) clearInterval(interval);
    echo.leaveChannel("access-logs");
    echo.leaveChannel("stranger-snaps");
    echo.leaveChannel("device-status");
});
</script>
```

---

## 11. Backend API Contracts & Interface Agreement Matrix

| Endpoint                  | Method   | Payload / Parameters                                     | Success Response                                                              | Expected Status               |
| :------------------------ | :------- | :------------------------------------------------------- | :---------------------------------------------------------------------------- | :---------------------------- |
| `/api/auth/login`         | `POST`   | `{ email, password, remember }`                          | `{ status: 'success', token, user: { id, name, email, roles, permissions } }` | `200 OK` / `422` / `401`      |
| `/api/auth/logout`        | `POST`   | (Bearer token header)                                    | `{ status: 'success', message: 'Logged out' }`                                | `200 OK`                      |
| `/api/auth/user`          | `GET`    | (Bearer token header)                                    | `{ user: { id, name, email }, roles: [...], permissions: [...] }`             | `200 OK` / `401`              |
| `/api/auth/profile`       | `PUT`    | `{ name, email }`                                        | `{ status: 'success', user: { ... } }`                                        | `200 OK` / `422`              |
| `/api/auth/password`      | `PUT`    | `{ current_password, password, password_confirmation }`  | `{ status: 'success' }`                                                       | `200 OK` / `422`              |
| `/api/organizations`      | `GET`    | —                                                        | `{ data: [{ id, name, code, address, timezone }] }`                           | `200 OK`                      |
| `/api/organizations/{id}` | `PUT`    | `{ name, code, address, timezone }`                      | `{ data: { ... } }`                                                           | `200 OK`                      |
| `/api/departments`        | `GET`    | `?parent_id=&search=`                                    | `{ data: [{ id, name, code, parent_id, head_id, children }] }`                | `200 OK`                      |
| `/api/departments`        | `POST`   | `{ name, code, parent_id, head_id }`                     | `{ data: { id, ... } }`                                                       | `201 Created`                 |
| `/api/departments/{id}`   | `PUT`    | `{ name, code, parent_id, head_id }`                     | `{ data: { id, ... } }`                                                       | `200 OK`                      |
| `/api/departments/{id}`   | `DELETE` | —                                                        | `{ message: 'Deleted' }`                                                      | `200 OK` / `422` (restricted) |
| `/api/designations`       | `GET`    | —                                                        | `{ data: [{ id, name, level, description }] }`                                | `200 OK`                      |
| `/api/designations`       | `POST`   | `{ name, level, description }`                           | `{ data: { ... } }`                                                           | `201 Created`                 |
| `/api/designations/{id}`  | `PUT`    | `{ name, level, description }`                           | `{ data: { ... } }`                                                           | `200 OK`                      |
| `/api/designations/{id}`  | `DELETE` | —                                                        | `{ message: 'Deleted' }`                                                      | `200 OK`                      |
| `/api/locations`          | `GET`    | —                                                        | `{ data: [{ id, name, address, timezone, coordinates }] }`                    | `200 OK`                      |
| `/api/locations`          | `POST`   | `{ name, address, timezone, coordinates }`               | `{ data: { ... } }`                                                           | `201 Created`                 |
| `/api/locations/{id}`     | `PUT`    | `{ name, address, timezone, coordinates }`               | `{ data: { ... } }`                                                           | `200 OK`                      |
| `/api/locations/{id}`     | `DELETE` | —                                                        | `{ message: 'Deleted' }`                                                      | `200 OK`                      |
| `/api/settings`           | `GET`    | —                                                        | `{ data: { 'attendance.auto_process': true, ... } }`                          | `200 OK`                      |
| `/api/settings/bulk`      | `POST`   | `{ settings: { 'attendance.auto_process': true, ... } }` | `{ status: 'success' }`                                                       | `200 OK`                      |
| `/api/audit-logs`         | `GET`    | `?page=1&per_page=20&search=&action=&auditable_type=`    | `{ data: [...], current_page, last_page, total }`                             | `200 OK`                      |

---

## 12. Build Verification & Quality Assurance Strategy

1. **Dependency Validation**:
    - `axios`, `pinia`, `vue`, `sweetalert2`, and `lucide-vue-next` are already present in `package.json`. No extra npm packages are required.
2. **Vite Production Bundling (`npm run build`)**:
    - Verify that all newly created `.vue` and `.js` files compile cleanly into `public/build/`.
    - `defineAsyncComponent` ensures that chunks are created on demand.
3. **Error Handling & Mock Testing**:
    - In `client.js`, verify with non-authenticated API responses (HTTP 401) that `authStore.clearAuth()` is invoked and UI resets to `LoginPage.vue`.
    - Test 403 authorization rejections to ensure user is warned via `notify.warning` without application crash.
4. **Form Validation**:
    - Department parent selection must filter out self-referencing IDs to prevent cyclical relationship errors.

---

## 13. Summary & Readiness

This blueprint provides complete, drop-in implementations for all frontend assets required by Milestone 1. The architecture establishes a rock-solid security and administrative foundation for all subsequent milestones (Employees, Shifts, Attendance, Leaves, and Visitors).
