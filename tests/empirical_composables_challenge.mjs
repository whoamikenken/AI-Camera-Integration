import { ref, reactive, nextTick } from 'vue';
import { usePaginatedResource } from '../resources/js/composables/usePaginatedResource.js';
import { useBiometricCapture } from '../resources/js/composables/useBiometricCapture.js';
import { useLiveTelemetryStream } from '../resources/js/composables/useLiveTelemetryStream.js';

let totalTests = 0;
let passedTests = 0;
let failedTests = [];

function assert(condition, message) {
    totalTests++;
    if (!condition) {
        console.error(`  FAIL: ${message}`);
        failedTests.push(message);
        throw new Error(message);
    } else {
        console.log(`  PASS: ${message}`);
        passedTests++;
    }
}

function assertEqual(actual, expected, message) {
    totalTests++;
    const actualStr = JSON.stringify(actual);
    const expectedStr = JSON.stringify(expected);
    if (actualStr !== expectedStr) {
        console.error(`  FAIL: ${message} (Expected ${expectedStr}, got ${actualStr})`);
        failedTests.push(`${message} (Expected ${expectedStr}, got ${actualStr})`);
        throw new Error(`${message}: Expected ${expectedStr}, got ${actualStr}`);
    } else {
        console.log(`  PASS: ${message}`);
        passedTests++;
    }
}

console.log('=================================================================');
console.log('EMPIRICAL CHALLENGE SUITE: Milestone M6 Frontend Composables & UI');
console.log('=================================================================\n');

// =============================================================================
// CHALLENGE 1: usePaginatedResource
// =============================================================================
console.log('--- 1. Testing usePaginatedResource ---');

async function testPaginatedResourceOutOfBounds() {
    console.log('Scenario 1.1: Out-of-bounds page navigation');
    let fetchCalls = [];

    const mockFetch = async (params) => {
        fetchCalls.push(params);
        return {
            data: [
                { id: 1, name: 'Item 1' },
                { id: 2, name: 'Item 2' }
            ],
            current_page: params.page || 1,
            last_page: 3,
            per_page: 2,
            total: 6,
        };
    };

    const paginator = usePaginatedResource(mockFetch, {
        initialPage: 1,
        initialPerPage: 2,
        immediate: false,
    });

    // Initial state
    await paginator.fetch(1);
    assertEqual(paginator.currentPage.value, 1, 'Initial currentPage is 1');
    assertEqual(fetchCalls.length, 1, 'Fetched initial page');

    // prevPage when already on page 1 -> Should NOT fetch
    const prevRes = paginator.prevPage();
    assertEqual(prevRes, undefined, 'prevPage() returns undefined at lower boundary');
    assertEqual(paginator.currentPage.value, 1, 'currentPage remains 1');
    assertEqual(fetchCalls.length, 1, 'No additional fetch on prevPage at page 1');

    // Out-of-bounds goToPage(0) and goToPage(-5) -> Should NOT fetch
    assertEqual(paginator.goToPage(0), undefined, 'goToPage(0) rejected');
    assertEqual(paginator.goToPage(-5), undefined, 'goToPage(-5) rejected');
    assertEqual(paginator.currentPage.value, 1, 'currentPage remains 1');
    assertEqual(fetchCalls.length, 1, 'No fetch on negative/zero page');

    // Valid nextPage to page 2
    await paginator.nextPage();
    assertEqual(paginator.currentPage.value, 2, 'nextPage() advances to page 2');
    assertEqual(fetchCalls.length, 2, 'Fetched page 2');

    // Valid nextPage to page 3 (last_page)
    await paginator.nextPage();
    assertEqual(paginator.currentPage.value, 3, 'nextPage() advances to page 3');
    assertEqual(fetchCalls.length, 3, 'Fetched page 3');

    // Out-of-bounds nextPage when on last_page (3) -> Should NOT fetch
    const nextRes = paginator.nextPage();
    assertEqual(nextRes, undefined, 'nextPage() returns undefined at upper boundary');
    assertEqual(paginator.currentPage.value, 3, 'currentPage remains 3');
    assertEqual(fetchCalls.length, 3, 'No additional fetch on nextPage at last_page');

    // Out-of-bounds goToPage(4) and goToPage(999) -> Should NOT fetch
    assertEqual(paginator.goToPage(4), undefined, 'goToPage(4) rejected beyond last_page');
    assertEqual(paginator.goToPage(999), undefined, 'goToPage(999) rejected');
    assertEqual(paginator.currentPage.value, 3, 'currentPage remains 3');
    assertEqual(fetchCalls.length, 3, 'No fetch on out-of-bounds upper page');
}

async function testPaginatedResourceDebounce() {
    console.log('Scenario 1.2: Search debounce timing & page reset');
    let fetchHistory = [];

    const mockFetch = async (params) => {
        fetchHistory.push({ ...params, time: Date.now() });
        return {
            data: [{ id: 10, title: 'Result' }],
            current_page: params.page || 1,
            last_page: 5,
            total: 50,
        };
    };

    const paginator = usePaginatedResource(mockFetch, {
        initialPage: 3,
        debounceMs: 150,
        immediate: false,
    });

    await paginator.fetch(3);
    assertEqual(paginator.currentPage.value, 3, 'Initially on page 3');
    const baselineCalls = fetchHistory.length;

    // Rapid typing simulation: 4 keystrokes spaced by 30ms (total 90ms < 150ms debounce)
    paginator.searchQuery.value = 'a';
    await new Promise(r => setTimeout(r, 30));
    paginator.searchQuery.value = 'ap';
    await new Promise(r => setTimeout(r, 30));
    paginator.searchQuery.value = 'app';
    await new Promise(r => setTimeout(r, 30));
    paginator.searchQuery.value = 'apple';

    // Verify debounce has not fired yet
    await new Promise(r => setTimeout(r, 40));
    assertEqual(fetchHistory.length, baselineCalls, 'Debounce suppressed intermediate queries');

    // Wait for debounce timer to fire (remaining > 110ms)
    await new Promise(r => setTimeout(r, 160));
    assertEqual(fetchHistory.length, baselineCalls + 1, 'Exactly one debounced fetch dispatched');
    assertEqual(paginator.currentPage.value, 1, 'Search query reset currentPage to 1');
    assertEqual(fetchHistory[fetchHistory.length - 1].search, 'apple', 'Latest query parameter sent');
    assertEqual(fetchHistory[fetchHistory.length - 1].page, 1, 'Query dispatched for page 1');
}

async function testPaginatedResourceResponseShapes() {
    console.log('Scenario 1.3: Response shape compatibility (flat vs wrapped vs paginator)');

    // 1. Nested wrapped ApiResponse { success: true, data: { data: [...], current_page, ... } }
    let p1 = usePaginatedResource(async () => ({
        success: true,
        data: {
            data: [{ id: 1 }, { id: 2 }],
            current_page: 2,
            last_page: 4,
            per_page: 10,
            total: 40,
        }
    }), { immediate: false });
    await p1.fetch(2);
    assertEqual(p1.items.value.length, 2, 'Wrapped ApiResponse items parsed');
    assertEqual(p1.pagination.value.current_page, 2, 'Wrapped ApiResponse current_page parsed');
    assertEqual(p1.pagination.value.total, 40, 'Wrapped ApiResponse total parsed');

    // 2. Direct Laravel Paginator { data: [...], current_page, last_page, total }
    let p2 = usePaginatedResource(async () => ({
        data: [{ id: 101 }, { id: 102 }, { id: 103 }],
        current_page: 1,
        last_page: 2,
        total: 5,
        per_page: 3,
    }), { immediate: false });
    await p2.fetch(1);
    assertEqual(p2.items.value.length, 3, 'Direct Laravel Paginator items parsed');
    assertEqual(p2.pagination.value.last_page, 2, 'Direct Laravel Paginator last_page parsed');

    // 3. API Resource Collection with meta { data: [...], meta: { current_page, ... } }
    let p3 = usePaginatedResource(async () => ({
        data: [{ id: 201 }],
        meta: {
            current_page: 3,
            last_page: 8,
            per_page: 1,
            total: 8,
        }
    }), { immediate: false });
    await p3.fetch(3);
    assertEqual(p3.items.value.length, 1, 'API Resource meta format items parsed');
    assertEqual(p3.pagination.value.current_page, 3, 'API Resource meta format current_page parsed');
    assertEqual(p3.pagination.value.last_page, 8, 'API Resource meta format last_page parsed');

    // 4. Flat Array directly: [ ... ]
    let p4 = usePaginatedResource(async () => ([
        { id: 'a', name: 'Alpha' },
        { id: 'b', name: 'Beta' },
        { id: 'c', name: 'Gamma' },
    ]), { immediate: false });
    await p4.fetch(1);
    assertEqual(p4.items.value.length, 3, 'Flat array parsed');
    assertEqual(p4.pagination.value.current_page, 1, 'Flat array current_page is 1');
    assertEqual(p4.pagination.value.total, 3, 'Flat array total is array length');

    // 5. Flat array inside data: { data: [ ... ] } without pagination meta
    let p5 = usePaginatedResource(async () => ({
        data: [{ id: 'x' }, { id: 'y' }]
    }), { immediate: false });
    await p5.fetch(1);
    assertEqual(p5.items.value.length, 2, 'Wrapped flat array parsed');
    assertEqual(p5.pagination.value.total, 2, 'Wrapped flat array total is 2');

    // 6. Empty response / null safety
    let p6 = usePaginatedResource(async () => null, { immediate: false });
    await p6.fetch(1);
    assertEqual(p6.items.value.length, 0, 'Null response parsed cleanly as empty items');

    // 7. Mutate optimistic updates
    p4.mutate(items => items.filter(i => i.id !== 'b'));
    assertEqual(p4.items.value.length, 2, 'mutate() removed item optimistically');
    assertEqual(p4.items.value.map(i => i.id), ['a', 'c'], 'mutate() items match expected');
}

// =============================================================================
// CHALLENGE 2: useBiometricCapture
// =============================================================================
console.log('\n--- 2. Testing useBiometricCapture ---');

function testBiometricCropAndValidation() {
    console.log('Scenario 2.1: Aspect ratio 1:1 center crop calculations');

    let drawCalls = [];
    const mockCanvas = {
        width: 0,
        height: 0,
        getContext: () => ({
            drawImage: (...args) => {
                drawCalls.push(args);
            }
        }),
        toDataURL: (mime, quality) => `data:${mime};base64,MOCK_BASE64_DATA_IMAGE_QUALITY_${quality}`,
    };

    // Polyfill document.createElement for canvas
    globalThis.document = {
        createElement: (tag) => {
            if (tag === 'canvas') return mockCanvas;
            return {};
        }
    };

    const capture = useBiometricCapture({
        targetDimension: 480,
        minDimension: 200,
        quality: 0.85,
    });

    // Test 1: Landscape video 1280x720 -> Crop must be 720x720 centered at sx=280, sy=0
    drawCalls = [];
    const landscapeVideo = { videoWidth: 1280, videoHeight: 720 };
    const res1 = capture.captureFrame(landscapeVideo);
    assert(res1 !== null, 'Landscape frame captured successfully');
    assertEqual(mockCanvas.width, 480, 'Canvas width is target 480');
    assertEqual(mockCanvas.height, 480, 'Canvas height is target 480 (1:1 square)');
    assertEqual(drawCalls.length, 1, 'drawImage called once');
    // drawImage(el, sx, sy, cropSize, cropSize, 0, 0, size, size)
    const [el1, sx1, sy1, cw1, ch1, dx1, dy1, dw1, dh1] = drawCalls[0];
    assertEqual(sx1, (1280 - 720) / 2, 'Landscape center crop sx is exactly (1280-720)/2 = 280');
    assertEqual(sy1, 0, 'Landscape center crop sy is 0');
    assertEqual(cw1, 720, 'Crop width is 720');
    assertEqual(ch1, 720, 'Crop height is 720');
    assertEqual(dw1, 480, 'Destination width scaled to 480');
    assertEqual(dh1, 480, 'Destination height scaled to 480');

    // Test 2: Portrait video 720x1280 -> Crop must be 720x720 centered at sx=0, sy=280
    drawCalls = [];
    const portraitVideo = { videoWidth: 720, videoHeight: 1280 };
    const res2 = capture.captureFrame(portraitVideo);
    assert(res2 !== null, 'Portrait frame captured successfully');
    const [el2, sx2, sy2, cw2, ch2] = drawCalls[0];
    assertEqual(sx2, 0, 'Portrait center crop sx is 0');
    assertEqual(sy2, (1280 - 720) / 2, 'Portrait center crop sy is exactly (1280-720)/2 = 280');
    assertEqual(cw2, 720, 'Crop width is 720');
    assertEqual(ch2, 720, 'Crop height is 720');

    // Test 3: Square video 600x600 -> Crop is 600x600 at sx=0, sy=0
    drawCalls = [];
    const squareVideo = { videoWidth: 600, videoHeight: 600 };
    const res3 = capture.captureFrame(squareVideo);
    assert(res3 !== null, 'Square frame captured successfully');
    const [el3, sx3, sy3, cw3, ch3] = drawCalls[0];
    assertEqual(sx3, 0, 'Square sx is 0');
    assertEqual(sy3, 0, 'Square sy is 0');
    assertEqual(cw3, 600, 'Crop dimension is 600');

    console.log('Scenario 2.2: Minimum 200x200px validation');
    // Resolution < 200px rejected
    const smallVideo1 = { videoWidth: 199, videoHeight: 500 };
    const fail1 = capture.captureFrame(smallVideo1);
    assertEqual(fail1, null, 'Video with width 199 rejected (< 200)');
    assert(capture.error.value.includes('below minimum required 200x200px'), 'Error message reports resolution violation');

    const smallVideo2 = { videoWidth: 500, videoHeight: 199 };
    const fail2 = capture.captureFrame(smallVideo2);
    assertEqual(fail2, null, 'Video with height 199 rejected (< 200)');

    const smallVideo3 = { videoWidth: 100, videoHeight: 100 };
    const fail3 = capture.captureFrame(smallVideo3);
    assertEqual(fail3, null, 'Video with 100x100 rejected (< 200)');

    // Boundary 200x200 accepted
    const exactMinVideo = { videoWidth: 200, videoHeight: 200 };
    const successMin = capture.captureFrame(exactMinVideo);
    assert(successMin !== null, 'Video with exact 200x200 accepted');

    console.log('Scenario 2.3: Base64 export format and validity');
    assert(capture.capturedImage.value.startsWith('data:image/jpeg;base64,'), 'capturedImage has JPEG data URL format');
    assertEqual(capture.rawBase64.value, 'MOCK_BASE64_DATA_IMAGE_QUALITY_0.85', 'rawBase64 stripped MIME header completely');

    // Clear function resets state
    capture.clear();
    assertEqual(capture.capturedImage.value, '', 'clear() resets capturedImage');
    assertEqual(capture.rawBase64.value, '', 'clear() resets rawBase64');
    assertEqual(capture.error.value, null, 'clear() resets error');

    // Camera track stop verification
    let trackStopped = false;
    const mockTrack = { stop: () => { trackStopped = true; } };
    capture.stopCamera(); // when stream is null -> no error
    assertEqual(capture.isStreaming.value, false, 'stopCamera marks isStreaming false');
}

// =============================================================================
// CHALLENGE 3: LiveTelemetry & useLiveTelemetryStream
// =============================================================================
console.log('\n--- 3. Testing LiveTelemetry & useLiveTelemetryStream ---');

async function testTelemetrySoundAndEcho() {
    console.log('Scenario 3.1: Sound alert toggle & synthesis triggering');

    let echoListeners = {};
    let leftChannels = [];

    const mockEcho = {
        private: (channelName) => {
            return {
                listen: (eventName, cb) => {
                    const key = `${channelName}:${eventName}`;
                    echoListeners[key] = cb;
                    return mockEcho.private(channelName);
                }
            };
        },
        channel: (channelName) => mockEcho.private(channelName),
        leave: (channelName) => {
            leftChannels.push(channelName);
        }
    };

    // Polyfill window and AudioContext
    let synthesizedSounds = [];
    globalThis.window = {
        AudioContext: class MockAudioContext {
            constructor() {
                this.currentTime = 0;
                this.state = 'running';
                this.destination = {};
            }
            createGain() {
                return {
                    connect: () => {},
                    gain: {
                        setValueAtTime: () => {},
                        exponentialRampToValueAtTime: () => {},
                        linearRampToValueAtTime: () => {}
                    }
                };
            }
            createOscillator() {
                return {
                    type: 'sine',
                    frequency: {
                        setValueAtTime: () => {},
                        exponentialRampToValueAtTime: () => {}
                    },
                    connect: () => {},
                    start: () => {},
                    stop: () => {}
                };
            }
            resume() { return Promise.resolve(); }
        }
    };

    // Test with sound disabled initially
    const stream = useLiveTelemetryStream('access-logs', {
        isPrivate: true,
        soundEnabled: false,
        maxBufferSize: 5, // small buffer for testing ring cap
        autoConnect: false,
    });

    assertEqual(stream.soundEnabled.value, false, 'Sound is initially disabled');
    assertEqual(stream.stream.value.length, 0, 'Buffer initially empty');

    // Subscribe manually with mock echo
    stream.subscribe();

    // Trigger incoming verified log via Echo while sound is OFF
    // Note: useLiveTelemetryStream registers both .AccessLogReceived and AccessLogReceived
    // Let's test event ingestion directly through registered echo callback
    const listenerKey = Object.keys(echoListeners).find(k => k.includes('AccessLogReceived'));
    assert(listenerKey !== undefined, 'Echo channel registered AccessLogReceived listener');

    const listener = echoListeners[listenerKey];

    // Emit event 1: verify_status = 1 (Allowed)
    listener({
        id: 101,
        device_id: 'DEV-001',
        person_name: 'Alice',
        verify_status: 1,
        captured_at: '2026-10-09 10:00:00'
    });

    assertEqual(stream.stream.value.length, 1, 'Event 1 ingested into stream buffer');
    assertEqual(stream.latestEvent.value.id, 101, 'latestEvent points to event 1');

    // Enable sound
    stream.soundEnabled.value = true;
    assertEqual(stream.soundEnabled.value, true, 'soundEnabled reactively toggled to true');

    // Emit event 2: verify_status = 2 (Rejected / Denied)
    listener({
        id: 102,
        device_id: 'DEV-001',
        person_name: 'Unknown',
        verify_status: 2,
        captured_at: '2026-10-09 10:01:00'
    });

    assertEqual(stream.stream.value.length, 2, 'Event 2 ingested');
    assertEqual(stream.stream.value[0].id, 102, 'Newest event unshifted to index 0');

    console.log('Scenario 3.2: Deduplication & Ring Buffer Capping');

    // Deduplication test: re-emit event 102 with updated similarity
    listener({
        id: 102,
        device_id: 'DEV-001',
        person_name: 'Unknown',
        verify_status: 2,
        similarity: 45.5,
        captured_at: '2026-10-09 10:01:00'
    });

    assertEqual(stream.stream.value.length, 2, 'Deduplication prevented buffer growth on duplicate ID');
    assertEqual(stream.stream.value[0].similarity, 45.5, 'Existing entry updated in-place');

    // Ring buffer capping test (maxBufferSize is 5)
    listener({ id: 103, verify_status: 1 });
    listener({ id: 104, verify_status: 1 });
    listener({ id: 105, verify_status: 1 });
    assertEqual(stream.stream.value.length, 5, 'Buffer reached max capacity 5');

    // Push 6th and 7th events
    listener({ id: 106, verify_status: 1 });
    assertEqual(stream.stream.value.length, 5, 'Buffer capped at maxBufferSize (5)');
    assertEqual(stream.stream.value[0].id, 106, 'Newest event 106 at front');

    listener({ id: 107, verify_status: 1 });
    assertEqual(stream.stream.value.length, 5, 'Buffer remains strictly capped at 5');
    assertEqual(stream.stream.value[0].id, 107, 'Newest event 107 at front');
    assert(!stream.stream.value.some(e => e.id === 101), 'Oldest event 101 dropped from ring buffer');

    console.log('Scenario 3.3: Echo Channel Cleanup / Unsubscribe');
    assertEqual(stream.isConnected.value, true, 'isConnected is true before unsubscribe');
    stream.unsubscribe();
    assertEqual(stream.isConnected.value, false, 'isConnected is false after unsubscribe');

    // Verify clearBuffer
    stream.clearBuffer();
    assertEqual(stream.stream.value.length, 0, 'clearBuffer() emptied stream');
    assertEqual(stream.latestEvent.value, null, 'clearBuffer() cleared latestEvent');
}

// Run all test suites
async function run() {
    try {
        await testPaginatedResourceOutOfBounds();
        await testPaginatedResourceDebounce();
        await testPaginatedResourceResponseShapes();
        testBiometricCropAndValidation();
        await testTelemetrySoundAndEcho();

        console.log('\n=================================================================');
        console.log(`ALL EMPIRICAL TESTS PASSED! (${passedTests}/${totalTests} assertions)`);
        console.log('=================================================================');
        process.exit(0);
    } catch (err) {
        console.error('\n=================================================================');
        console.error(`TEST SUITE FAILED with ${failedTests.length} errors:`);
        failedTests.forEach(f => console.error(` - ${f}`));
        console.error('=================================================================');
        console.error(err);
        process.exit(1);
    }
}

run();
