/**
 * Formats a media URL for Vue components.
 * Signed URLs and blob/data URIs are returned as-is without exposing
 * authentication bearer tokens in URL query strings (SEC-14).
 *
 * @param {string|null|undefined} url
 * @return {string}
 */
export function formatMediaUrl(url) {
    if (!url) return '';
    return url;
}

export default formatMediaUrl;
