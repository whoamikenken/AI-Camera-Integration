self.wasmReady = false;

self.Module = {
  locateFile: function (path) {
    return "/player/" + path;
  },
  onRuntimeInitialized: function () {
    self.wasmReady = true;
    WasmStart();
  },
};

self.importScripts("./decoder.js");

function DecoderWorker() {
  this.decoder_handle = 0;

  this.video_queue = [];
  setInterval(this.video_decode.bind(this), 10);

  this.audio_queue = [];
  setInterval(this.audio_listen_decode.bind(this), 2);
}

// 视频解码
DecoderWorker.prototype.video_decode = function () {
  if (!self.wasmReady || !this.decoder_handle || typeof Module._malloc !== "function") {
    return;
  }

  if (this.video_queue.length > 0) {
    var video_data = this.video_queue.shift();
    var array = new Uint8Array(video_data);
    var tmp_buff = Module._malloc(array.length);
    Module.HEAPU8.set(array, tmp_buff);
    let video_decode_data = Module._video_decode(
      this.decoder_handle,
      tmp_buff,
      array.length
    );
    if (0 != video_decode_data) {
      let width = Module.HEAPU32[video_decode_data / 4];
      let height = Module.HEAPU32[video_decode_data / 4 + 1];
      let zoom = width / height;
      let is_key_frame = Module.HEAPU32[video_decode_data / 4 + 2];
      let time = Module.HEAPU32[video_decode_data / 4 + 3];
      let y = Module.HEAPU32[video_decode_data / 4 + 4];
      let u = Module.HEAPU32[video_decode_data / 4 + 5];
      let v = Module.HEAPU32[video_decode_data / 4 + 6];
      let snap_data_len = Module.HEAPU32[video_decode_data / 4 + 7];
      let snap_data = Module.HEAPU32[video_decode_data / 4 + 8];
      let total = width * height;
      let uv_size = total / 4;
      var out_y = Module.HEAPU8.slice(y, y + total);
      var out_u = Module.HEAPU8.slice(u, u + uv_size);
      var out_v = Module.HEAPU8.slice(v, v + uv_size);

      var res_msg = {
        cmd: "video_live_decode_result",
        width: width,
        height: height,
        is_key_frame: is_key_frame,
        time: time,
        y: out_y,
        u: out_u,
        v: out_v,
        zoom: zoom,
      };
      self.postMessage(res_msg);

      // 抓拍数据
      if (0 != snap_data && snap_data_len > 0) {
        var out_snap_data = Module.HEAPU8.slice(
          snap_data,
          snap_data + snap_data_len
        );

        var snap_res_msg = {
          cmd: "snap_result",
          snap_data_len: snap_data_len,
          snap_data: out_snap_data,
        };
        self.postMessage(snap_res_msg);
        Module._snap_free(this.decoder_handle);
      }

      Module._free(video_decode_data);
    }
    Module._free(tmp_buff);
  }

  return;
};

// 音频监听解码
DecoderWorker.prototype.audio_listen_decode = function () {
  if (!self.wasmReady || !this.decoder_handle || typeof Module._malloc !== "function") {
    return;
  }

  if (this.audio_queue.length > 0) {
    var audio_listen_data = this.audio_queue.shift();
    var array = new Uint8Array(audio_listen_data);
    var tmp_buff = Module._malloc(array.length);
    Module.HEAPU8.set(array, tmp_buff);
    let audio_listen_decode_data = Module._audio_listen_decode(
      this.decoder_handle,
      tmp_buff,
      array.length
    );
    if (0 != audio_listen_decode_data) {
      let chn_count = Module.HEAPU32[audio_listen_decode_data / 4];
      let bit_width = Module.HEAPU32[audio_listen_decode_data / 4 + 1];
      let sample_rate = Module.HEAPU32[audio_listen_decode_data / 4 + 2];
      let pcm_data_len = Module.HEAPU32[audio_listen_decode_data / 4 + 3];
      let pcm_data = Module.HEAPU32[audio_listen_decode_data / 4 + 4];

      var out_pcm_data = Module.HEAPU8.slice(pcm_data, pcm_data + pcm_data_len);

      var res_msg = {
        cmd: "audio_listen_decode_result",
        chn_count: chn_count,
        bit_width: bit_width,
        sample_rate: sample_rate,
        pcm_data_len: pcm_data_len,
        pcm_data: out_pcm_data,
      };
      self.postMessage(res_msg);

      Module._free(audio_listen_decode_data);
    }
    Module._free(tmp_buff);
  }
};

self.decoder_worker = new DecoderWorker();

self.onmessage = function (event) {
  if (null == self.decoder_worker) {
    return;
  }
  switch (event.data.cmd) {
    // 视频直播解码
    case "video_live_decode":
      self.decoder_worker.video_queue.push(event.data.data);
      if (self.wasmReady && self.decoder_worker.decoder_handle && typeof Module._set_need_key_frame === "function") {
        if (
          self.decoder_worker.video_queue.length > 60 &&
          self.decoder_worker.video_queue.length < 120
        ) {
          Module._set_need_key_frame(self.decoder_worker.decoder_handle);
        } else if (self.decoder_worker.video_queue.length >= 120) {
          self.decoder_worker.video_queue = [];
          Module._set_need_key_frame(self.decoder_worker.decoder_handle);
        }
      }
      break;
    // 抓拍
    case "snap":
      if (self.wasmReady && self.decoder_worker.decoder_handle && typeof Module._snap === "function") {
        Module._snap(self.decoder_worker.decoder_handle);
      }
      break;
    // 音频监听解码
    case "audio_listen_decode":
      self.decoder_worker.audio_queue.push(event.data.data);

      if (
        self.decoder_worker.audio_queue.length > 100 &&
        self.decoder_worker.audio_queue.length < 200
      ) {
        self.decoder_worker.audio_queue.splice(0, 10);
      } else if (self.decoder_worker.audio_queue.length >= 200) {
        self.decoder_worker.audio_queue = [];
      }
      break;
    case "clear_video_queue":
      self.decoder_worker.video_queue = [];
      break;
  }
};

function WasmStart() {
  if (self.decoder_worker && typeof Module._create_decoder === "function") {
    self.decoder_worker.decoder_handle = Module._create_decoder();

    var res_msg = {
      cmd: "video_live_request_key_frame",
    };
    self.postMessage(res_msg);
  }
}
