import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import axios from 'axios';

window.Pusher = Pusher;

const isHttps = (typeof window !== 'undefined' && window.location?.protocol === 'https:') || (import.meta.env.VITE_REVERB_SCHEME === 'https');
const wsHost = (typeof window !== 'undefined' && window.location?.hostname) || import.meta.env.VITE_REVERB_HOST || 'localhost';
const configuredPort = import.meta.env.VITE_REVERB_PORT 
    ? parseInt(import.meta.env.VITE_REVERB_PORT, 10) 
    : (isHttps ? 443 : (typeof window !== 'undefined' && window.location?.port ? parseInt(window.location.port, 10) : 8080));

const echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY || 'camera_hub_key',
    wsHost: wsHost,
    wsPort: configuredPort,
    wssPort: configuredPort,
    forceTLS: isHttps,
    enabledTransports: ['ws', 'wss'],
    authEndpoint: '/broadcasting/auth',
    authorizer: (channel, options) => {
        return {
            authorize: (socketId, callback) => {
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                const token = localStorage.getItem('auth_token');
                const headers = {
                    'X-Socket-Id': socketId,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                };
                if (csrfToken) headers['X-CSRF-TOKEN'] = csrfToken;
                if (token) headers['Authorization'] = `Bearer ${token}`;

                axios.post('/broadcasting/auth', {
                    socket_id: socketId,
                    channel_name: channel.name
                }, {
                    headers,
                    withCredentials: true,
                })
                .then(response => {
                    callback(null, response.data);
                })
                .catch(error => {
                    callback(error);
                });
            }
        };
    },
});

export default echo;
