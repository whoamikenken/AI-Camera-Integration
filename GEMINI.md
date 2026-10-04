# Intelligent AI Camera Hub - System Architecture & Developer Guidelines

This document outlines the system architecture, component breakdown, database schema, operational workflows, and technical guidelines for the **Intelligent AI Camera Hub**.

---

## 1. System Overview

The **Intelligent AI Camera Hub** is a centralized access control, biometric identity synchronization, and real-time vision telemetry platform designed for network-based face recognition and smart AI cameras (specifically X40Y and related edge hardware).

The system implements a **Pure WAN MQTT Architecture**:

- **Edge Camera Bootstrap**: Initial MQTT broker parameters (Broker Host/IP, Port 1883, Topics, and Authentication) are manually configured directly on the camera device via its built-in Web interface.
- **Asynchronous Telemetry Streaming (WAN / MQTT Uplink)**: Ingests high-frequency real-time verification logs (`VerifyPush`), stranger snapshots (`StrSnapPush`), behavioral infractions, and device heartbeats (`HeartBeat`) via an MQTT broker subscribing to camera topic streams (`mqtt/face/<DeviceID>/*`).
- **Bidirectional WAN Command Dispatch (MQTT Downlink)**: Manages personnel enrollment, whitelist/blacklist provisioning, credentials, face templates, diagnostics, remote reboots, and camera settings via MQTT downlink commands (`mqtt/face/<DeviceID>`) with request-reply confirmation (`mqtt/face/<DeviceID>/Ack`).
- **Real-Time Client Broadcasting**: Distributes ingested telemetry events and attendance updates to live web dashboards via WebSockets (Laravel Reverb) with minimal latency.

---

## 2. Technology Stack

| Layer                      | Technology                | Version / Specification      | Role in System                                                    |
| :------------------------- | :------------------------ | :--------------------------- | :---------------------------------------------------------------- |
| **Backend Framework**      | Laravel                   | PHP 8.2+ / Laravel 11+       | REST APIs, authentication, job orchestration, business logic      |
| **Queue & Monitoring**     | Laravel Horizon           | Redis-backed                 | Asynchronous worker pool for device provisioning (`camera-sync`)  |
| **Frontend Framework**     | Vue 3                     | Composition API, Vite, Pinia | Live streaming dashboard, device manager, personnel CRUD UI       |
| **Styling & UI**           | Tailwind CSS / Shadcn Vue | Modern dark/light design     | Responsive, high-density analytics interface                      |
| **Primary Database**       | PostgreSQL                | 16+                          | Relational data: devices, personnel, access logs, sync tasks      |
| **Cache & Key-Value**      | Redis                     | 7.x+                         | Job queues, Horizon telemetry state, distributed locks, caching   |
| **MQTT Ingestion Broker**  | EMQX / Mosquitto          | MQTT v3.1.1 (QoS 0)          | Ingests real-time camera events, alarms, and heartbeat packets    |
| **MQTT Ingestion Daemon**  | `php-mqtt/client`         | Long-running CLI worker      | Persistent daemon listening to MQTT topics and dispatching events |
| **Real-Time Broadcasting** | Laravel Reverb            | WebSockets (Pusher protocol) | Live push of access and alert events directly to Vue 3 UI         |
| **Process Supervision**    | Supervisord / Systemd     | Linux daemon manager         | Manages `queue:work`, `horizon`, and `mqtt:listen` processes      |

---

## 3. High-Level System Architecture

```mermaid
flowchart TD
    subgraph Frontend["Vue 3 Frontend (Vite + Pinia)"]
        UI["Live Access Dashboard & Management Console"]
    end

    subgraph RealTime["Real-Time Transport"]
        Reverb["Laravel Reverb (WebSockets)"]
    end

    subgraph Backend["Laravel Core Services (PHP 8.2+)"]
        API["REST API & Web Controllers"]
        DB[(PostgreSQL 16 Database)]
        RedisCache[(Redis Cache & Queues)]
        Horizon["Laravel Horizon Worker Pool"]
    end

    subgraph Workers["Background Daemons (Supervisord)"]
        MQTTSyncWorker["WAN MQTT Sync Worker\n(Queue: camera-sync)"]
        MQTTDaemon["MQTT Telemetry Daemon\n(php artisan mqtt:listen)"]
    end

    subgraph Broker["Message Broker"]
        MQTTBroker["MQTT Broker (EMQX / Mosquitto :1883)"]
    end

    subgraph EdgeDevices["Edge Camera Infrastructure (WAN)"]
        Camera["Intelligent AI Camera (X40Y)\nConnected via WAN / Cellular / Ethernet"]
    end

    UI <-->|HTTP REST / Auth| API
    UI <-->|WebSocket Stream| Reverb
    API <--> DB
    API <--> RedisCache

    API -->|Dispatch Sync Jobs| RedisCache
    RedisCache --> Horizon
    Horizon --> MQTTSyncWorker

    MQTTSyncWorker -->|MQTT Pub: mqtt/face/{ID}\nEditPerson, DelPerson, Reboot| MQTTBroker
    MQTTBroker -->|Downlink Commands| Camera

    Camera -->|MQTT Pub: mqtt/face/{ID}/*\nRecPush, StrSnapPush, HeartBeat| MQTTBroker
    MQTTBroker -->|Sub: mqtt/face/#| MQTTDaemon
    MQTTDaemon -->|Store Access Logs| DB
    MQTTDaemon -->|Broadcast Event| Reverb
```

---

## 4. PostgreSQL Database Schema

```sql
-- 1. Devices Table
CREATE TABLE devices (
    id SERIAL PRIMARY KEY,
    device_id VARCHAR(64) UNIQUE NOT NULL,       -- e.g., '1299517' or '005a213b000b93cc'
    name VARCHAR(128) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,             -- e.g., '192.168.1.100' or domain
    port INT DEFAULT 1883,
    username VARCHAR(64) DEFAULT 'admin',
    password VARCHAR(64) DEFAULT 'admin',
    device_type INT DEFAULT 0,                   -- 0: IPC, 1: DVR, 2: NVR, 3: Panel Unit
    mqtt_topic VARCHAR(128),                     -- e.g., 'mqtt/face/1299517'
    is_active BOOLEAN DEFAULT TRUE,
    last_heartbeat_at TIMESTAMP WITH TIME ZONE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 2. Personnel / Face Library
CREATE TABLE personnel (
    id SERIAL PRIMARY KEY,
    customize_id INT UNIQUE NOT NULL,            -- Custom unique numeric ID (IdType=0)
    person_uuid UUID DEFAULT gen_random_uuid(),  -- UUID alternative (IdType=2)
    name VARCHAR(64) NOT NULL,
    person_type INT DEFAULT 0,                   -- 0: Whitelist (Allow), 1: Blacklist (Block)
    gender INT DEFAULT 0,                        -- 0: Male, 1: Female
    id_card VARCHAR(32),
    tel_num VARCHAR(32),
    address VARCHAR(128),
    birthday DATE,
    temp_valid INT DEFAULT 0,                    -- 0: Permanent, 1: Temporary
    valid_begin TIMESTAMP WITH TIME ZONE,
    valid_end TIMESTAMP WITH TIME ZONE,
    effect_number INT DEFAULT 1,                 -- -1: Infinite, 1-10000: Finite passes
    photo_path VARCHAR(255),                     -- Local disk path or Cloud S3 URL
    photo_base64 TEXT,                           -- Optional cached Base64 representation
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 3. Access & Verification Telemetry Logs
CREATE TABLE access_logs (
    id BIGSERIAL PRIMARY KEY,
    device_id VARCHAR(64) REFERENCES devices(device_id) ON DELETE CASCADE,
    person_id INT,                               -- Device internal ID
    customize_id INT,
    person_uuid UUID,
    person_name VARCHAR(64),
    verify_status INT NOT NULL,                  -- 1: Allowed, 2: Rejected, 3: Not Registered
    verify_type INT DEFAULT 1,                   -- 1: Whitelist, 2: ID Card, 3: Card+Face
    person_type INT DEFAULT 0,                   -- 0: Whitelist, 1: Blacklist
    similarity NUMERIC(5, 2),                    -- Match score (0.00 to 100.00)
    snap_pic_url VARCHAR(255),                   -- Stored snapshot image URL
    scene_pic_url VARCHAR(255),                  -- Stored full scene image URL
    captured_at TIMESTAMP WITH TIME ZONE NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 4. Stranger Capture & Detection Alerts
CREATE TABLE stranger_snaps (
    id BIGSERIAL PRIMARY KEY,
    device_id VARCHAR(64) REFERENCES devices(device_id) ON DELETE CASCADE,
    snap_pic_url VARCHAR(255) NOT NULL,
    scene_pic_url VARCHAR(255),
    captured_at TIMESTAMP WITH TIME ZONE NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 5. Device Sync Outbox / Job Queue Tracking
CREATE TABLE sync_tasks (
    id BIGSERIAL PRIMARY KEY,
    device_id VARCHAR(64) REFERENCES devices(device_id) ON DELETE CASCADE,
    personnel_id INT REFERENCES personnel(id) ON DELETE CASCADE,
    action VARCHAR(32) NOT NULL,                 -- 'ADD', 'EDIT', 'DELETE'
    status VARCHAR(20) DEFAULT 'PENDING',        -- 'PENDING', 'PROCESSING', 'COMPLETED', 'FAILED'
    attempts INT DEFAULT 0,
    error_message TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
```

---

## 5. Subsystem Component Breakdown

### A. Core Laravel Backend & API Services

- **Camera Management Service (`App\Services\CameraService` / `App\Services\CameraMqttService`)**: Formulates JSON payloads and dispatches downlink MQTT commands to camera hardware topics (`mqtt/face/<DeviceID>`) using `PhpMqtt\Client\MqttClient`.
- **Personnel Sync Observer (`App\Observers\PersonnelObserver`)**: Automatically dispatches `SyncPersonnelJob` to the `camera-sync` Redis queue whenever a personnel record or facial image is created, updated, or removed.
- **Storage Manager (`App\Services\ImageStorageService`)**: Ingests Base64 image payloads received from MQTT telemetry, saves binaries to local disk or S3/R2 storage, and generates public storage URLs for the UI.

### B. WAN MQTT Dispatcher (Edge Sync Worker)

- Runs as a dedicated Redis queue worker: `php artisan queue:work redis --queue=camera-sync`
- Dispatches personnel sync operations (`EditPerson`, `AddPersons`, `DelPerson`, `DeletePersons`) to cameras across WAN via the MQTT broker topic `mqtt/face/<DeviceID>`.
- Tracks acknowledgments and execution status in the `sync_tasks` table.

### C. Cloud MQTT Telemetry Daemon

- Long-running Laravel CLI command: `php artisan mqtt:listen` powered by `php-mqtt/client`.
- Subscribes to configured camera wildcard topics (e.g. `mqtt/face/+/Rec`, `mqtt/face/+/Snap`, `mqtt/face/heartbeat`, `mqtt/face/basic`).
- **Event Handling**:
    - `RecPush`: Extracts `customId`, `VerifyStatus`, `similarity1`, saves to `access_logs`, and fires `AccessLogReceived` event over Laravel Reverb.
    - `StrSnapPush`: Extracts snapshot images, saves to `stranger_snaps`, and notifies UI.
    - `HeartBeat`: Updates `devices.last_heartbeat_at` for online presence tracking.
    - `Online` / `Offline`: Detects hardware boots and Last Will and Testament disconnections.
    - Sends MQTT `PushAck` confirmation packets when continuous transmission (`ResumefromBreakpoint`) is enabled.

### D. Vue 3 Real-Time Frontend

- **Live Event Monitor**: Real-time access log feed displaying matched photo, employee name, similarity percentage, admission status badge (Allowed / Denied), and timestamps via WebSockets.
- **Personnel Directory**: Manage users, upload/crop facial images, set temporary/permanent access schedules, and trigger manual syncs.
- **Device Management Panel**: Configure device parameters, monitor online/offline heartbeat status, dispatch downlink commands (reboot, parameter queries, clock sync), and verify connectivity over MQTT.

---

## 6. Camera Protocol Reference Mapping (Pure MQTT WAN)

| Operation                    | Protocol / Topic                | Channel           | Key Payload Parameters                                                             |
| :--------------------------- | :------------------------------ | :---------------- | :--------------------------------------------------------------------------------- |
| **Add / Update Person**      | `mqtt/face/{DeviceID}`          | MQTT (Downlink)   | `operator: "EditPerson"`, `facesluiceId`, `customId`, `name`, `pic` / `picURI`    |
| **Batch Add Persons**        | `mqtt/face/{DeviceID}`          | MQTT (Downlink)   | `operator: "AddPersons"`, `PersonNum`, `Personinfo_0: {...}`                       |
| **Delete Person**            | `mqtt/face/{DeviceID}`          | MQTT (Downlink)   | `operator: "DelPerson"` / `"DeletePersons"`, `customId: [...]`                     |
| **Delete All Persons**       | `mqtt/face/{DeviceID}`          | MQTT (Downlink)   | `operator: "DeleteAllPerson"`, `deleteall: 1`                                      |
| **Search List**              | `mqtt/face/{DeviceID}`          | MQTT (Downlink)   | `operator: "SearchPersonList"`, `PersonType: 2`, `BeginNO: 0`, `RequestCount: 50`  |
| **Update MQTT Settings**     | `mqtt/face/{DeviceID}`          | MQTT (Downlink)   | `operator: "UpMQTTconfig"`, `StrangerUploadType`, `RecordUploadType`, `KeepAlive`  |
| **Reboot Camera**            | `mqtt/face/{DeviceID}`          | MQTT (Downlink)   | `operator: "RebootDevice"`, `facesluiceId`                                         |
| **Time Synchronization**     | `mqtt/face/{DeviceID}`          | MQTT (Downlink)   | `operator: "SetSysTime"`, `time: "YYYY-MM-DD hh:mm:ss"`                            |
| **Device Information**       | `mqtt/face/{DeviceID}`          | MQTT (Downlink)   | `operator: "GetDeviceInformation"`, `facesluiceId`                                 |
| **Live Verification Stream** | `mqtt/face/{DeviceID}/Rec`      | MQTT (Uplink)     | `VerifyPush` (`VerifyStatus`, `similarity1`, `pic`, `scene`)                       |
| **Stranger Alert Stream**    | `mqtt/face/{DeviceID}/Snap`     | MQTT (Uplink)     | `StrSnapPush` (`time`, `pic`, `scene`)                                             |
| **Heartbeat Stream**         | `mqtt/face/heartbeat`           | MQTT (Uplink)     | `HeartBeat` (`facesluiceId`, `time`)                                               |
| **Online / Offline Status**  | `mqtt/face/basic`               | MQTT (Uplink/LWT) | `Online` / `Offline` (`facesluiceId`, `ip`, `time`)                                |

---

## 7. Execution & Deployment Checklist

1. **Database Setup**:
    - Run PostgreSQL 16 migrations to establish tables: `devices`, `personnel`, `access_logs`, `stranger_snaps`, `sync_tasks`.
2. **MQTT Broker Configuration**:
    - Launch EMQX or Mosquitto on port `1883`.
    - Configure broker credentials if authentication is enabled.
3. **Camera Bootstrap (Manual Setup)**:
    - Log in to each camera's local web configuration page.
    - Configure the MQTT server settings (Host, Port 1883, Topic prefix `mqtt/face/<DeviceID>`, keepalive interval).
4. **Daemon Deployment (Supervisord)**:
    - Configure supervisor programs for:
        - `php artisan horizon` (or `php artisan queue:work --queue=camera-sync`)
        - `php artisan mqtt:listen`
        - `php artisan reverb:start`
5. **Frontend Launch**:
    - Build frontend application (`npm run build`) and point WebSocket connection to Laravel Reverb.
