# AI Camera Integration Documentation

This folder contains protocol specifications, interface documentation, and system design notes for integrating AI cameras and edge video analytics devices into the **Intelligent Vision Edge & Telemetry Hub**.

---

## System Design & Architecture

- **[System Design & Architecture Document](file:///home/whoamiken/AI-Camera-Integration/docs/system-design/system_design.md)** (also mirrored in [GEMINI.md](file:///home/whoamiken/AI-Camera-Integration/GEMINI.md))
  - Full system architectural blueprint covering the decoupled hybrid architecture (Laravel 11, Vue 3, PostgreSQL 16, Redis, EMQX/Mosquitto, Laravel Reverb).
  - Complete PostgreSQL 16 relational database schema for devices, personnel, access logs, stranger snapshots, and outbox sync tasks.
  - End-to-end sequence diagrams for LAN provisioning and real-time MQTT WebSocket streaming.
  - Supervisord production runbook and execution checklist.

---

## Protocol Documentation Index

| Documentation File | Protocol & Version | Transport | Key Use Case / Mode |
| :--- | :--- | :--- | :--- |
| **[http_protocol_v1.13.md](file:///home/whoamiken/AI-Camera-Integration/docs/http_protocol_v1.13.md)** | Intelligent Camera HTTP Protocol V1.13 | Direct HTTP POST | Local Area Network (LAN) direct control, face database enrollment, and HTTP webhook event subscriptions (`/Subscribe/Snap`, `/Subscribe/Verify`, `/Subscribe/heartbeat`). |
| **[mqtt_protocol_v1.25.md](file:///home/whoamiken/AI-Camera-Integration/docs/mqtt_protocol_v1.25.md)** | Intelligent Camera MQTT Protocol V1.25 | MQTT v3.1.1 (QoS 0) | Cloud & IoT broker integration, asynchronous message dispatching (`mqtt/face/<ID>`), batch face enrollments, AI analytics streams, and 4G SIM integration. |
| **[wan_http_reverse_polling_protocol_v1.01.md](file:///home/whoamiken/AI-Camera-Integration/docs/wan_http_reverse_polling_protocol_v1.01.md)** | X40Y WAN HTTP Interface Protocol V1.01 | HTTP Reverse-Polling | Cameras behind NAT / Firewalls / Intranets without public IPs; commands are piggybacked in the cloud server's HTTP 200 OK heartbeat response. |

---

## Original Reference PDFs

The original vendor documentation PDFs are stored in the [`docs/api/`](file:///home/whoamiken/AI-Camera-Integration/docs/api) directory:
- [ipc-http-x40y.pdf](file:///home/whoamiken/AI-Camera-Integration/docs/api/ipc-http-x40y.pdf) &mdash; HTTP Protocol of Intelligent Network Camera V1.13
- [ipc-mqtt-en_v20250526.pdf](file:///home/whoamiken/AI-Camera-Integration/docs/api/ipc-mqtt-en_v20250526.pdf) &mdash; MQTT Protocol of Intelligent Network Camera V1.25
- [X40Y WideAreaNetworkHTTPInterfaceProtocol.pdf](file:///home/whoamiken/AI-Camera-Integration/docs/api/X40Y%20WideAreaNetworkHTTPInterfaceProtocol.pdf) &mdash; X40Y WAN HTTP Protocol V1.01
