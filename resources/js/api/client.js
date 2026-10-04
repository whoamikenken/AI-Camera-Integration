import axios from 'axios';
import notify from '../utils/notify';

/**
 * Retrieve CSRF token from page meta tag
 */
function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

/**
 * Create primary Axios instance
 */
export const apiClient = axios.create({
    baseURL: '/api',
    headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
    withCredentials: true,
    timeout: 30000,
});

// Configure global axios defaults & interceptors as well
const applyRequestAuth = (config) => {
    const csrfToken = getCsrfToken();
    if (csrfToken) {
        config.headers['X-CSRF-TOKEN'] = csrfToken;
    }

    const token = localStorage.getItem('auth_token');
    if (token && !config.headers.Authorization) {
        config.headers.Authorization = `Bearer ${token}`;
    }

    if (config.url) {
        if (config.url.startsWith('/api/')) {
            config.url = config.url.substring(4);
        } else if (config.url === '/api') {
            config.url = '';
        }
    }

    return config;
};

// Global axios interceptor for any bare imports
axios.interceptors.request.use((config) => {
    const csrfToken = getCsrfToken();
    if (csrfToken) {
        config.headers['X-CSRF-TOKEN'] = csrfToken;
    }
    const token = localStorage.getItem('auth_token');
    if (token && !config.headers.Authorization) {
        config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
}, (error) => Promise.reject(error));

/**
 * Request Interceptor
 * - Injects CSRF token
 * - Injects Bearer token from localStorage if available
 * - Normalizes URL to avoid duplicate /api prefixes
 */
apiClient.interceptors.request.use(applyRequestAuth, (error) => Promise.reject(error));

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
            notify.error('Network Connection Error', 'Unable to reach backend API. Please verify server connectivity.');
            return Promise.reject(error);
        }

        const { status, data } = response;

        switch (status) {
            case 401: {
                localStorage.removeItem('auth_token');
                localStorage.removeItem('auth_user');
                localStorage.removeItem('auth_roles');
                localStorage.removeItem('auth_permissions');

                // Notify shell to transition to login view
                window.dispatchEvent(new CustomEvent('auth:unauthorized', {
                    detail: { message: data?.message || 'Session expired. Please log in again.' }
                }));
                break;
            }

            case 403: {
                notify.warning(
                    'Permission Denied',
                    data?.message || 'You do not have administrative privileges to execute this action.'
                );
                break;
            }

            case 422: {
                let firstMsg = data?.message || 'Validation error.';
                if (data?.errors && typeof data.errors === 'object') {
                    const errorArrays = Object.values(data.errors);
                    if (errorArrays.length > 0 && Array.isArray(errorArrays[0]) && errorArrays[0].length > 0) {
                        firstMsg = errorArrays[0][0];
                    }
                }
                notify.error('Validation Error', firstMsg);
                break;
            }

            case 404: {
                break;
            }

            case 500:
            case 502:
            case 503: {
                notify.error(
                    'Server Error',
                    data?.message || `Internal server error (HTTP ${status}). Please check system logs.`
                );
                break;
            }
        }

        return Promise.reject(error);
    }
);

export default apiClient;
