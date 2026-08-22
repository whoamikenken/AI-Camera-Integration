/**
 * Date and Time formatters configured for Asia/Manila (PHT, UTC+8)
 */

export function formatTime(timestamp) {
  if (!timestamp) return '--:--:--';
  const d = new Date(timestamp);
  if (isNaN(d.getTime())) return '--:--:--';
  return d.toLocaleTimeString('en-US', {
    timeZone: 'Asia/Manila',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: true,
  });
}

export function formatDateTime(timestamp) {
  if (!timestamp) return '--';
  const d = new Date(timestamp);
  if (isNaN(d.getTime())) return '--';
  return d.toLocaleString('en-US', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: true,
  });
}
