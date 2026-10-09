import { ref, computed, onUnmounted } from 'vue';

export function useBiometricCapture(options = {}) {
    const {
        targetDimension = 480,
        targetSize = targetDimension,
        minDimension = 200,
        minDimensions = minDimension,
        quality = 0.9,
        facingMode = 'user',
    } = options;

    const actualTarget = targetSize || targetDimension;
    const actualMin = minDimensions || minDimension;

    const isStreaming = ref(false);
    const error = ref(null);
    const capturedImage = ref('');

    const rawBase64 = computed(() => {
        if (!capturedImage.value) return '';
        return capturedImage.value.replace(/^data:image\/[a-zA-Z]+;base64,/, '');
    });

    const hasSupport = computed(() => {
        return typeof navigator !== 'undefined' && !!navigator.mediaDevices?.getUserMedia;
    });

    let currentStream = null;

    async function startCamera(videoElement, userConstraints = {}) {
        error.value = null;
        if (!hasSupport.value) {
            error.value = 'Webcam not supported by your browser.';
            return false;
        }

        const constraints = {
            video: {
                width: { ideal: 1280 },
                height: { ideal: 720 },
                facingMode,
                ...userConstraints,
            },
        };

        try {
            stopCamera();
            currentStream = await navigator.mediaDevices.getUserMedia(constraints);

            if (videoElement) {
                const el = videoElement.value !== undefined ? videoElement.value : videoElement;
                if (el) {
                    el.srcObject = currentStream;
                    await el.play().catch(() => {});
                }
            }

            isStreaming.value = true;
            return true;
        } catch (err) {
            console.warn('startCamera failed:', err);
            error.value = err.name === 'NotAllowedError'
                ? 'Camera access denied by user.'
                : 'Could not initialize camera device.';
            isStreaming.value = false;
            return false;
        }
    }

    function stopCamera() {
        if (currentStream) {
            currentStream.getTracks().forEach(track => track.stop());
            currentStream = null;
        }
        isStreaming.value = false;
    }

    function captureFrame(videoElement, size = actualTarget) {
        error.value = null;
        const el = videoElement?.value !== undefined ? videoElement.value : videoElement;
        if (!el) {
            error.value = 'Video element not available for capture.';
            return null;
        }

        const vw = el.videoWidth || 640;
        const vh = el.videoHeight || 480;

        if (vw < actualMin || vh < actualMin) {
            error.value = `Video resolution (${vw}x${vh}) is below minimum required ${actualMin}x${actualMin}px.`;
            return null;
        }

        // Calculate 1:1 square center crop coordinates
        const cropSize = Math.min(vw, vh);
        const sx = (vw - cropSize) / 2;
        const sy = (vh - cropSize) / 2;

        const canvas = document.createElement('canvas');
        canvas.width = size;
        canvas.height = size;
        const ctx = canvas.getContext('2d');

        ctx.drawImage(el, sx, sy, cropSize, cropSize, 0, 0, size, size);

        const dataUrl = canvas.toDataURL('image/jpeg', quality);
        capturedImage.value = dataUrl;
        return dataUrl;
    }

    function capturePhoto(videoElement, size = actualTarget) {
        return captureFrame(videoElement, size);
    }

    async function processImageFile(file, size = actualTarget) {
        error.value = null;
        return new Promise((resolve, reject) => {
            if (!file || !file.type.startsWith('image/')) {
                error.value = 'Invalid file type. Please upload an image.';
                return reject(new Error(error.value));
            }

            const reader = new FileReader();
            reader.onload = (e) => {
                const img = new Image();
                img.onload = () => {
                    const iw = img.naturalWidth;
                    const ih = img.naturalHeight;

                    if (iw < actualMin || ih < actualMin) {
                        error.value = `Image resolution (${iw}x${ih}) is below minimum required ${actualMin}x${actualMin}px.`;
                        return reject(new Error(error.value));
                    }

                    const cropSize = Math.min(iw, ih);
                    const sx = (iw - cropSize) / 2;
                    const sy = (ih - cropSize) / 2;

                    const canvas = document.createElement('canvas');
                    canvas.width = size;
                    canvas.height = size;
                    const ctx = canvas.getContext('2d');

                    ctx.drawImage(img, sx, sy, cropSize, cropSize, 0, 0, size, size);
                    const dataUrl = canvas.toDataURL('image/jpeg', quality);
                    capturedImage.value = dataUrl;
                    resolve(dataUrl);
                };
                img.onerror = () => {
                    error.value = 'Failed to load image for processing.';
                    reject(new Error(error.value));
                };
                img.src = e.target.result;
            };
            reader.onerror = () => {
                error.value = 'Failed to read file.';
                reject(new Error(error.value));
            };
            reader.readAsDataURL(file);
        });
    }

    function clear() {
        capturedImage.value = '';
        error.value = null;
    }

    function clearPhoto() {
        clear();
    }

    onUnmounted(() => {
        stopCamera();
    });

    return {
        isStreaming,
        hasSupport,
        hasCamera: hasSupport,
        error,
        capturedImage,
        rawBase64,
        startCamera,
        stopCamera,
        capturePhoto,
        captureFrame,
        clearPhoto,
        clear,
        processImageFile,
    };
}

export default useBiometricCapture;
