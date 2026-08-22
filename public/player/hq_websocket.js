// 负责websocket数据发送和接收转发
// message说明:
//   发送数据到服务器格式:
//     cmd:'',      // 命令:'ws_send_string'- 发送字符串,此时msg为字符串
//     url:'',      // url:websocket服务器地址
//     msg:''       // 发送的消息体
//   从服务器接收数据格式:
//     cmd:'',      // 命令:'ws_recv_string'- 收到字符串,此时msg为字符串
//                  //      'ws_recv_bin'- 收到二进制数据,此时msg为ArrayBuffer
//     msg:''       // 接收的消息体

let socketID = 0;
function HQWebSocket() {
  console.log("new HQWebSocket():", socketID);
  this.ws_sock = null;
  this.socket_id = socketID++;
}

self.hq_websocket = new HQWebSocket();

function OnWebSocketError() {
  var res_msg = {
    cmd: "ws_socket_error",
    msg: null,
  };
  self.postMessage(res_msg);
}

function OnWebSocketSuccess() {
    var res_msg = {
        cmd: "ws_socket_success",
        msg: null,
    };
    self.postMessage(res_msg);
}

HQWebSocket.prototype.send_msg = function (url, msg, msg_cb) {
  if (null == this.ws_sock) {
    console.log("new websocket:", this.socket_id);
    this.ws_sock = new WebSocket(url);
    if (!this.ws_sock) {
      OnWebSocketError();
      return;
    }

    this.ws_sock.binaryType = "arraybuffer";

    var self = this;
    this.ws_sock.onopen = function (event) {
        OnWebSocketSuccess();
      self.ws_sock.send(msg);
    };

    this.ws_sock.onerror = function (event) {
      console.log("连接错误:", event);
      if (self.ws_sock) {
        self.ws_sock = null;
        OnWebSocketError();
      }
    };

    this.ws_sock.onclose = function (event) {
      console.log(
        "连接断开, url:" +
          url +
          ", code:" +
          event.code +
          ", reason:" +
          event.reason
      );
      if (self.ws_sock) {
        self.ws_sock = null;
        OnWebSocketError();
      }
    };

    this.ws_sock.onmessage = msg_cb;
  // } else {
  //   this.ws_sock.onmessage = msg_cb;
  //   this.ws_sock.send(msg);
  // }
} else if (this.ws_sock.readyState === WebSocket.OPEN) {
  this.ws_sock.send(msg);
} else {
  console.log("WebSocket 连接尚未建立，MSG:",msg);
}
};

function ws_msg_cb(event) {
  var recv_msg = event.data;
  var types = typeof recv_msg;

  if (types == "string") {
    var res_msg = {
      cmd: "ws_recv_string",
      msg: recv_msg,
    };
    self.postMessage(res_msg);
  } else if (recv_msg instanceof ArrayBuffer) {
    var res_msg = {
      cmd: "ws_recv_bin",
      msg: recv_msg,
    };
    self.postMessage(res_msg);
  }
}

self.onmessage = function (event) {
  if (event.data.cmd == "ws_send_string") {
    self.hq_websocket.send_msg(event.data.url, event.data.msg, ws_msg_cb);
  } else {
    console.log("unknown event:" + event);
  }
};
