import { md5 } from './md5';

/**
 * WebGL YUV420P Frame Renderer
 */
class WebGLYUVRenderer {
  constructor(canvas) {
    this.canvas = canvas;
    this.gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
    if (!this.gl) {
      throw new Error('WebGL not supported');
    }
    this.initGL();
  }

  initGL() {
    const gl = this.gl;
    gl.pixelStorei(gl.UNPACK_ALIGNMENT, 1);

    const vertexShaderSource = `
      attribute highp vec4 aVertexPosition;
      attribute vec2 aTextureCoord;
      varying highp vec2 vTextureCoord;
      void main(void) {
        gl_Position = aVertexPosition;
        vTextureCoord = aTextureCoord;
      }
    `;

    const fragmentShaderSource = `
      precision highp float;
      varying highp vec2 vTextureCoord;
      uniform sampler2D YTexture;
      uniform sampler2D UTexture;
      uniform sampler2D VTexture;
      void main(void) {
        mediump vec3 yuv;
        mediump vec3 rgb;
        yuv.x = texture2D(YTexture, vTextureCoord).r;
        yuv.y = texture2D(UTexture, vTextureCoord).r - 0.5;
        yuv.z = texture2D(VTexture, vTextureCoord).r - 0.5;
        rgb.r = yuv.x + 1.402 * yuv.z;
        rgb.g = yuv.x - 0.34414 * yuv.y - 0.71414 * yuv.z;
        rgb.b = yuv.x + 1.772 * yuv.y;
        gl_FragColor = vec4(rgb, 1.0);
      }
    `;

    const program = gl.createProgram();
    const vs = this.compileShader(gl.VERTEX_SHADER, vertexShaderSource);
    const fs = this.compileShader(gl.FRAGMENT_SHADER, fragmentShaderSource);

    gl.attachShader(program, vs);
    gl.attachShader(program, fs);
    gl.linkProgram(program);

    if (!gl.getProgramParameter(program, gl.LINK_STATUS)) {
      throw new Error('Unable to initialize WebGL shaders');
    }

    gl.useProgram(program);
    this.program = program;

    const vertexPositionAttribute = gl.getAttribLocation(program, 'aVertexPosition');
    gl.enableVertexAttribArray(vertexPositionAttribute);

    const textureCoordAttribute = gl.getAttribLocation(program, 'aTextureCoord');
    gl.enableVertexAttribArray(textureCoordAttribute);

    const vertices = new Float32Array([
      -1.0, -1.0, 0.0,
       1.0, -1.0, 0.0,
      -1.0,  1.0, 0.0,
       1.0,  1.0, 0.0,
    ]);

    const vertexBuffer = gl.createBuffer();
    gl.bindBuffer(gl.ARRAY_BUFFER, vertexBuffer);
    gl.bufferData(gl.ARRAY_BUFFER, vertices, gl.STATIC_DRAW);
    gl.vertexAttribPointer(vertexPositionAttribute, 3, gl.FLOAT, false, 0, 0);

    const textureCoords = new Float32Array([
      0.0, 1.0,
      1.0, 1.0,
      0.0, 0.0,
      1.0, 0.0,
    ]);

    const textureCoordBuffer = gl.createBuffer();
    gl.bindBuffer(gl.ARRAY_BUFFER, textureCoordBuffer);
    gl.bufferData(gl.ARRAY_BUFFER, textureCoords, gl.STATIC_DRAW);
    gl.vertexAttribPointer(textureCoordAttribute, 2, gl.FLOAT, false, 0, 0);

    this.yTexture = this.createTexture(0, 'YTexture');
    this.uTexture = this.createTexture(1, 'UTexture');
    this.vTexture = this.createTexture(2, 'VTexture');
  }

  compileShader(type, source) {
    const gl = this.gl;
    const shader = gl.createShader(type);
    gl.shaderSource(shader, source);
    gl.compileShader(shader);
    if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
      const err = gl.getShaderInfoLog(shader);
      gl.deleteShader(shader);
      throw new Error(err);
    }
    return shader;
  }

  createTexture(unit, uniformName) {
    const gl = this.gl;
    const texture = gl.createTexture();
    gl.activeTexture(gl.TEXTURE0 + unit);
    gl.bindTexture(gl.TEXTURE_2D, texture);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
    gl.uniform1i(gl.getUniformLocation(this.program, uniformName), unit);
    return texture;
  }

  fillTexture(texture, unit, width, height, data) {
    const gl = this.gl;
    gl.activeTexture(gl.TEXTURE0 + unit);
    gl.bindTexture(gl.TEXTURE_2D, texture);
    gl.texImage2D(gl.TEXTURE_2D, 0, gl.LUMINANCE, width, height, 0, gl.LUMINANCE, gl.UNSIGNED_BYTE, data);
  }

  renderFrame(width, height, yData, uData, vData) {
    if (!this.gl) return;
    const gl = this.gl;

    if (this.canvas.width !== width || this.canvas.height !== height) {
      this.canvas.width = width;
      this.canvas.height = height;
      gl.viewport(0, 0, width, height);
    }

    this.fillTexture(this.yTexture, 0, width, height, yData);
    this.fillTexture(this.uTexture, 1, width / 2, height / 2, uData);
    this.fillTexture(this.vTexture, 2, width / 2, height / 2, vData);

    gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);
  }

  destroy() {
    if (this.gl) {
      try {
        const ext = this.gl.getExtension('WEBGL_lose_context');
        if (ext) ext.loseContext();
      } catch (e) {
        // ignore
      }
      this.gl = null;
    }
  }
}

/**
 * Camera WebSocket Live Stream Player
 */
export class CameraHqPlayer {
  constructor(canvas, options = {}) {
    this.canvas = canvas;
    this.host = options.host || '192.168.1.100';
    this.port = options.port || 80;
    this.username = options.username || 'admin';
    this.password = options.password || 'admin';
    this.streamType = options.streamType || 0; // 0: Main (1080P), 1: Sub (720P/VGA)
    this.channel = options.channel || 0;
    this.onStatus = options.onStatus || (() => {});
    this.onError = options.onError || (() => {});
    this.onDecodeInfo = options.onDecodeInfo || (() => {});

    this.ws = null;
    this.decoderWorker = null;
    this.renderer = null;
    this.isDestroyed = false;
    this.isLoggedIn = false;
    this.key = null;
    this.reconnectTimer = null;
    this.fpsCounter = 0;
    this.fpsTimer = null;
    this.bytesReceived = 0;

    this.init();
  }

  init() {
    try {
      this.renderer = new WebGLYUVRenderer(this.canvas);
    } catch (err) {
      this.onError('WebGL initialization failed: ' + err.message);
      return;
    }

    this.initDecoderWorker();
    this.connectWebSocket();
    this.startFpsTimer();
  }

  initDecoderWorker() {
    try {
      this.decoderWorker = new Worker('/player/decoder_worker.js');
      this.decoderWorker.onmessage = (e) => {
        if (this.isDestroyed) return;
        const msg = e.data;
        if (!msg) return;

        if (msg.cmd === 'video_live_decode_result') {
          if (msg.y && msg.u && msg.v) {
            this.renderer.renderFrame(msg.width, msg.height, msg.y, msg.u, msg.v);
            this.fpsCounter++;
            this.onDecodeInfo({
              width: msg.width,
              height: msg.height,
              isKeyFrame: msg.is_key_frame,
              time: msg.time,
            });
          }
        } else if (msg.cmd === 'video_live_request_key_frame') {
          if (this.isLoggedIn && this.ws && this.ws.readyState === WebSocket.OPEN) {
            this.sendJson({
              cmd: 4018,
              data: {
                chn: this.channel,
                st_type: this.streamType,
              },
            });
          }
        }
      };

      this.decoderWorker.onerror = (err) => {
        console.error('Decoder Worker Error:', err);
      };
    } catch (e) {
      this.onError('Failed to initialize decoder worker: ' + e.message);
    }
  }

  connectWebSocket() {
    if (this.isDestroyed) return;
    this.onStatus({ state: 'CONNECTING', message: `Connecting to ws://${this.host}/...` });

    const wsUrl = `ws://${this.host}/`;

    try {
      this.ws = new WebSocket(wsUrl);
      this.ws.binaryType = 'arraybuffer';

      this.ws.onopen = () => {
        if (this.isDestroyed) return;
        this.onStatus({ state: 'HANDSHAKE', message: 'Requesting authentication handshake...' });
        // Step 1: Send cmd 1001 to get auth key
        this.sendJson({ cmd: 1001 });
      };

      this.ws.onmessage = (event) => {
        if (this.isDestroyed) return;

        if (typeof event.data === 'string') {
          try {
            const data = JSON.parse(event.data);
            this.handleJsonMessage(data);
          } catch (e) {
            console.warn('Invalid JSON from camera WS:', event.data);
          }
        } else if (event.data instanceof ArrayBuffer) {
          this.handleBinaryMessage(event.data);
        }
      };

      this.ws.onerror = (err) => {
        console.error('Camera WebSocket error:', err);
        this.onError('WebSocket connection error');
      };

      this.ws.onclose = () => {
        if (this.isDestroyed) return;
        this.onStatus({ state: 'DISCONNECTED', message: 'WebSocket connection closed' });
        this.scheduleReconnect();
      };
    } catch (err) {
      this.onError('Failed to create WebSocket: ' + err.message);
      this.scheduleReconnect();
    }
  }

  handleJsonMessage(data) {
    if (!data) return;

    // Step 2: Receive Key (cmd: 1001)
    if (data.cmd === 1001) {
      if (data.code !== 0) {
        this.onError(`Key negotiation error (code: ${data.code})`);
        return;
      }
      this.key = data.data?.key;
      // Compute hashed password: md5(username + password + key)
      const hashedPassword = md5(this.username + this.password + this.key);

      this.onStatus({ state: 'AUTHENTICATING', message: 'Logging into camera...' });
      // Step 3: Send login request (cmd: 1002)
      this.sendJson({
        cmd: 1002,
        data: {
          user: this.username,
          passwd: hashedPassword,
          key: this.key,
          client_type: 1, // Web client
        },
      });
    }
    // Step 4: Login response (cmd: 1002)
    else if (data.cmd === 1002) {
      if (data.code === 0) {
        this.isLoggedIn = true;
        this.onStatus({ state: 'STREAMING', message: 'Live video feed active' });
        this.startLiveStream();
      } else {
        this.onError(`Authentication failed (code: ${data.code}, password/username mismatch)`);
      }
    }
  }

  startLiveStream() {
    if (!this.ws || this.ws.readyState !== WebSocket.OPEN) return;

    // Step 5: Start Video Stream (cmd: 4000)
    this.sendJson({
      cmd: 4000,
      data: {
        chn: this.channel,
        st_type: this.streamType, // 0: Main stream (1080P), 1: Sub stream (720P)
        oper: 0, // 0: Start
      },
    });

    // Step 6: Request immediate key frame (cmd: 4018)
    this.sendJson({
      cmd: 4018,
      data: {
        chn: this.channel,
        st_type: this.streamType,
      },
    });
  }

  handleBinaryMessage(arrayBuffer) {
    this.bytesReceived += arrayBuffer.byteLength;
    if (!this.decoderWorker) return;

    const u8 = new Uint8Array(arrayBuffer, 0, 28);
    // Magic check [79, 179]
    if (u8[0] !== 79 || u8[1] !== 179) return;

    // Type 1 or 2 is video
    if (u8[8] === 1 || u8[8] === 2) {
      this.decoderWorker.postMessage(
        {
          cmd: 'video_live_decode',
          data: arrayBuffer,
        },
        [arrayBuffer]
      );
    }
  }

  sendJson(obj) {
    if (this.ws && this.ws.readyState === WebSocket.OPEN) {
      this.ws.send(JSON.stringify(obj));
    }
  }

  switchStream(streamType) {
    if (this.streamType === streamType) return;
    this.streamType = streamType;

    if (this.isLoggedIn && this.ws && this.ws.readyState === WebSocket.OPEN) {
      // Stop current
      this.sendJson({
        cmd: 4000,
        data: {
          chn: this.channel,
          st_type: this.streamType === 0 ? 1 : 0,
          oper: 2, // 2: Stop
        },
      });

      // Clear video queue in worker
      if (this.decoderWorker) {
        this.decoderWorker.postMessage({ cmd: 'clear_video_queue' });
      }

      // Start new
      this.startLiveStream();
    }
  }

  takeSnapshot() {
    if (!this.canvas) return null;
    return this.canvas.toDataURL('image/jpeg', 0.95);
  }

  startFpsTimer() {
    this.fpsTimer = setInterval(() => {
      const currentFps = this.fpsCounter;
      this.fpsCounter = 0;
      this.onDecodeInfo({ fps: currentFps, bitrateKbps: Math.round((this.bytesReceived * 8) / 1024) });
      this.bytesReceived = 0;
    }, 1000);
  }

  scheduleReconnect() {
    if (this.isDestroyed || this.reconnectTimer) return;
    this.reconnectTimer = setTimeout(() => {
      this.reconnectTimer = null;
      if (!this.isDestroyed) {
        this.connectWebSocket();
      }
    }, 3000);
  }

  destroy() {
    this.isDestroyed = true;
    if (this.reconnectTimer) {
      clearTimeout(this.reconnectTimer);
      this.reconnectTimer = null;
    }
    if (this.fpsTimer) {
      clearInterval(this.fpsTimer);
      this.fpsTimer = null;
    }

    if (this.ws) {
      try {
        if (this.ws.readyState === WebSocket.OPEN && this.isLoggedIn) {
          this.sendJson({
            cmd: 4000,
            data: {
              chn: this.channel,
              st_type: this.streamType,
              oper: 2,
            },
          });
        }
        this.ws.close();
      } catch (e) {
        // ignore
      }
      this.ws = null;
    }

    if (this.decoderWorker) {
      try {
        this.decoderWorker.terminate();
      } catch (e) {
        // ignore
      }
      this.decoderWorker = null;
    }

    if (this.renderer) {
      this.renderer.destroy();
      this.renderer = null;
    }
  }
}
