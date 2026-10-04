/**
 * Formats a media URL for <img> tags in Vue components so that Sanctum authentication
 * tokens are attached via query string when fetching protected /api/media/ routes.
 *
 * @param {string|null|undefined} url
 * @return {string}
 */
export function formatMediaUrl(url) {
    if (!url) return '';

    // If it is already a base64 data URI or blob, return as-is
    if (url.startsWith('data:') || url.startsWith('blob:')) {
        return url;
    }

    const token = localStorage.getItem('auth_token');
    if (token && url.includes('/api/media/')) {
        const separator = url.includes('?') ? '&' : '?';
        if (!url.includes('token=')) {
            return `${url}${separator}token=${encodeURIComponent(token)}`;
        }
    }

    return url;
}

export default formatMediaUrl;
