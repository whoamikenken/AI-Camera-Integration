import { defineStore } from 'pinia';
import apiClient from '../api/client';
import notify from '../utils/notify';

function getStoredJson(key, fallback) {
    try {
        const item = localStorage.getItem(key);
        return item ? JSON.parse(item) : fallback;
    } catch {
        return fallback;
    }
}

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: getStoredJson('auth_user', null),
        token: localStorage.getItem('auth_token') || null,
        roles: getStoredJson('auth_roles', []),
        permissions: getStoredJson('auth_permissions', []),
        initialized: false,
        loading: false,
        loginError: null,
    }),

    getters: {
        isAuthenticated: (state) => !!state.token && !!state.user,
        
        // RBAC Role Checkers
        hasRole: (state) => (role) => {
            if (state.roles.includes('super-admin')) return true;
            return state.roles.includes(role);
        },

        hasAnyRole: (state) => (rolesToCheck) => {
            if (state.roles.includes('super-admin')) return true;
            return rolesToCheck.some((r) => state.roles.includes(r));
        },

        // RBAC Permission Checkers (with super-admin wildcard)
        can: (state) => (permission) => {
            if (state.roles.includes('super-admin')) return true;
            return state.permissions.includes(permission);
        },

        hasPermission: (state) => (permission) => {
            if (state.roles.includes('super-admin')) return true;
            return state.permissions.includes(permission);
        },

        cannot: (state) => (permission) => {
            if (state.roles.includes('super-admin')) return false;
            return !state.permissions.includes(permission);
        },

        // Primary Role Flags
        isSuperAdmin: (state) => state.roles.includes('super-admin'),
        isAdmin: (state) => state.roles.includes('super-admin') || state.roles.includes('admin'),
        isHrManager: (state) => state.roles.includes('super-admin') || state.roles.includes('admin') || state.roles.includes('hr-manager'),
        isSecurity: (state) => state.roles.includes('super-admin') || state.roles.includes('admin') || state.roles.includes('security'),
        isReceptionist: (state) => state.roles.includes('super-admin') || state.roles.includes('admin') || state.roles.includes('receptionist'),
        isManager: (state) => state.roles.includes('super-admin') || state.roles.includes('admin') || state.roles.includes('manager'),
        isEmployee: (state) => state.roles.includes('employee'),

        // Profile Display Helpers
        userName: (state) => state.user?.name || 'Administrator',
        userEmail: (state) => state.user?.email || '',
        primaryRoleBadge: (state) => {
            if (state.roles.includes('super-admin')) return 'Super Admin';
            if (state.roles.includes('admin')) return 'Admin';
            if (state.roles.includes('hr-manager')) return 'HR Manager';
            if (state.roles.includes('security')) return 'Security';
            if (state.roles.includes('receptionist')) return 'Receptionist';
            if (state.roles.includes('manager')) return 'Manager';
            if (state.roles.includes('employee')) return 'Employee';
            return 'Staff';
        },
        userInitials: (state) => {
            const name = state.user?.name || 'Admin User';
            const parts = name.trim().split(/\s+/);
            if (parts.length === 1) return parts[0].substring(0, 2).toUpperCase();
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
            window.addEventListener('auth:unauthorized', () => {
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
                const response = await apiClient.post('/auth/login', credentials);
                const data = response.data;

                const token = data.token || data.access_token;
                const user = data.user;
                const roles = data.roles || user.roles?.map(r => typeof r === 'string' ? r : r.slug || r.name) || [];
                const permissions = data.permissions || user.permissions?.map(p => typeof p === 'string' ? p : p.slug || p.name) || [];

                this.token = token;
                this.user = user;
                this.roles = roles;
                this.permissions = permissions;

                localStorage.setItem('auth_token', token);
                localStorage.setItem('auth_user', JSON.stringify(user));
                localStorage.setItem('auth_roles', JSON.stringify(roles));
                localStorage.setItem('auth_permissions', JSON.stringify(permissions));

                notify.toast(`Welcome back, ${user.name}!`, 'success');
                return { success: true, user };
            } catch (err) {
                const msg = err.response?.data?.message || 'Invalid email or password credentials.';
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
                const response = await apiClient.get('/auth/user');
                const data = response.data;
                const user = data.user || data;

                const roles = data.roles || user.roles?.map(r => typeof r === 'string' ? r : r.slug || r.name) || [];
                const permissions = data.permissions || user.permissions?.map(p => typeof p === 'string' ? p : p.slug || p.name) || [];

                this.user = user;
                this.roles = roles;
                this.permissions = permissions;

                localStorage.setItem('auth_user', JSON.stringify(user));
                localStorage.setItem('auth_roles', JSON.stringify(roles));
                localStorage.setItem('auth_permissions', JSON.stringify(permissions));

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
                    await apiClient.post('/auth/logout');
                }
            } catch (err) {
                console.warn('Logout API error, clearing local credentials:', err);
            } finally {
                this.clearAuth();
                this.loading = false;
                notify.toast('Signed out successfully.', 'info');
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
            localStorage.removeItem('auth_token');
            localStorage.removeItem('auth_user');
            localStorage.removeItem('auth_roles');
            localStorage.removeItem('auth_permissions');
        },

        /**
         * Update authenticated user profile details
         */
        async updateProfile(profileData) {
            const res = await apiClient.put('/auth/profile', profileData);
            this.user = { ...this.user, ...res.data.user };
            localStorage.setItem('auth_user', JSON.stringify(this.user));
            notify.toast('Profile updated successfully!', 'success');
            return res.data;
        },

        /**
         * Change user password
         */
        async changePassword(passwordData) {
            const res = await apiClient.put('/auth/password', passwordData);
            notify.success('Password Changed', 'Your password has been securely updated.');
            return res.data;
        }
    }
});
