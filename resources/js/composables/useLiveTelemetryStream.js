import { ref, computed, onMounted, onUnmounted } from 'vue';
import echo from '../echo';

let sharedAudioCtx = null;

function getAudioContext() {
    if (typeof window === 'undefined') return null;
    if (!sharedAudioCtx) {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (AudioCtx) sharedAudioCtx = new AudioCtx();
    }
    if (sharedAudioCtx && sharedAudioCtx.state === 'suspended') {
        sharedAudioCtx.resume().catch(() => {});
    }
    return sharedAudioCtx;
}

export function playSynthesizedChime(type = 'chime') {
    try {
        const ctx = getAudioContext();
        if (!ctx) return;

        const now = ctx.currentTime;
        const gain = ctx.createGain();
        gain.connect(ctx.destination);

        if (type === 'chime' || type === 'success' || type === 'allowed') {
            // Harmonic dual chime (C5 523.25Hz -> E5 659.25Hz)
            const osc1 = ctx.createOscillator();
            const osc2 = ctx.createOscillator();
            osc1.type = 'sine';
            osc2.type = 'sine';
            osc1.frequency.setValueAtTime(523.25, now);
            osc2.frequency.setValueAtTime(659.25, now + 0.1);

            gain.gain.setValueAtTime(0.15, now);
            gain.gain.exponentialRampToValueAtTime(0.001, now + 0.4);

            osc1.connect(gain);
            osc2.connect(gain);
            osc1.start(now);
            osc1.stop(now + 0.2);
            osc2.start(now + 0.1);
            osc2.stop(now + 0.4);
        } else {
            // Dissonant warning pulse (440Hz -> 220Hz sawtooth wave)
            const osc = ctx.createOscillator();
            osc.type = 'sawtooth';
            osc.frequency.setValueAtTime(440, now);
            osc.frequency.exponentialRampToValueAtTime(220, now + 0.3);

            gain.gain.setValueAtTime(0.2, now);
            gain.gain.linearRampToValueAtTime(0.01, now + 0.3);

            osc.connect(gain);
            osc.start(now);
            osc.stop(now + 0.3);
        }
    } catch (err) {
        // Audio policy or silent failure handling
    }
}

export function useLiveTelemetryStream(channelOrOptions = 'access-logs', maybeOptions = {}) {
    let channelName = typeof channelOrOptions === 'string' ? channelOrOptions : null;
    let options = typeof channelOrOptions === 'object' && channelOrOptions !== null ? channelOrOptions : maybeOptions;

    if (!channelName && options.channels) {
        channelName = Array.isArray(options.channels) ? options.channels[0] : options.channels;
    }
    if (!channelName) {
        channelName = 'access-logs';
    }

    const {
        isPrivate = true,
        events = {},
        soundEnabled: initialSound = false,
        enableAudio = false,
        maxBufferSize = 50,
        deduplicateBy = null,
        autoConnect = true,
        onEvent = null,
    } = options;

    const stream = ref([]);
    const lastEvent = ref(null);
    const isConnected = ref(false);
    const soundEnabled = ref(initialSound || enableAudio);

    let channelInstance = null;

    function defaultKey(item) {
        if (!item) return Math.random().toString();
        return item.id ? String(item.id) : `${item.captured_at}_${item.device_id || item.facesluiceId || ''}`;
    }

    const keyResolver = typeof deduplicateBy === 'function' ? deduplicateBy : defaultKey;

    function handleIncomingPacket(eventName, rawData) {
        let payload = rawData;
        if (payload && typeof payload.data === 'object' && !Array.isArray(payload.data)) {
            payload = payload.data;
        }

        lastEvent.value = payload;

        // Deduplication & unshift buffer
        const key = keyResolver(payload);
        const index = stream.value.findIndex(item => keyResolver(item) === key);

        if (index !== -1) {
            stream.value[index] = { ...stream.value[index], ...payload };
            stream.value = [...stream.value];
        } else {
            stream.value = [payload, ...stream.value];
            if (stream.value.length > maxBufferSize) {
                stream.value.pop();
            }
        }

        // Optional Audio Alert Chime
        if (soundEnabled.value) {
            const isDenied = Number(payload.verify_status) === 2 || payload.severity === 'CRITICAL' || payload.severity === 'HIGH';
            playSynthesizedChime(isDenied ? 'alert' : 'chime');
        }

        if (typeof onEvent === 'function') {
            onEvent(payload, eventName);
        }

        if (typeof events === 'object' && events[eventName] && typeof events[eventName] === 'function') {
            events[eventName](payload);
        }
    }

    function subscribe() {
        if (!channelName || channelInstance) return;

        try {
            channelInstance = isPrivate ? echo.private(channelName) : echo.channel(channelName);
            isConnected.value = true;

            const eventList = Array.isArray(events) ? [...events] : Object.keys(events);
            if (eventList.length === 0) {
                if (channelName === 'access-logs') eventList.push('AccessLogReceived');
                if (channelName === 'stranger-snaps') eventList.push('StrangerSnapReceived');
                if (channelName === 'device-alerts' || channelName === 'alerts') eventList.push('DeviceAlertReceived', 'DeviceAlertUpdated');
                if (channelName === 'attendance') eventList.push('AttendancePunchReceived');
                if (channelName === 'sync-tasks') eventList.push('SyncTaskUpdated');
            }

            eventList.forEach(evt => {
                const dotEvt = evt.startsWith('.') ? evt : `.${evt}`;
                const plainEvt = evt.startsWith('.') ? evt.substring(1) : evt;

                channelInstance
                    .listen(dotEvt, (data) => handleIncomingPacket(plainEvt, data))
                    .listen(plainEvt, (data) => handleIncomingPacket(plainEvt, data));
            });
        } catch (err) {
            console.warn('Echo subscribe error:', err);
            isConnected.value = false;
        }
    }

    function unsubscribe() {
        if (!channelInstance) return;
        try {
            echo.leave(channelName);
        } catch (err) {
            // ignore
        }
        channelInstance = null;
        isConnected.value = false;
    }

    function playChime(type = 'chime') {
        playSynthesizedChime(type);
    }

    function clearBuffer() {
        stream.value = [];
        lastEvent.value = null;
    }

    if (autoConnect) {
        onMounted(() => subscribe());
        onUnmounted(() => unsubscribe());
    }

    return {
        events: stream,
        stream,
        latestEvent: lastEvent,
        lastEvent,
        isConnected,
        soundEnabled,
        eventCount: computed(() => stream.value.length),
        subscribe,
        unsubscribe,
        playChime,
        clearEvents: clearBuffer,
        clearBuffer,
    };
}

export default useLiveTelemetryStream;
