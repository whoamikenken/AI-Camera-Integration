# Intelligent Vision Edge & Telemetry Hub - System Architecture & Developer Guidelines

This document outlines the system architecture, component breakdown, database schema, operational workflows, and technical guidelines for the **Intelligent Vision Edge & Telemetry Hub**.

---

## 1. System Overview

The **Intelligent Vision Edge & Telemetry Hub** is a centralized access control, biometric identity synchronization, and real-time vision telemetry platform designed for network-based face recognition and smart AI cameras (specifically X40Y and related edge hardware).

The system implements a **Decoupled Hybrid Architecture**:
- **Synchronous Device Control (LAN)**: Manages personnel enrollment, whitelist/blacklist provisioning, credentials, face templates, and camera hardware settings via direct HTTP POST requests (`/action/<Operator>`) with HTTP Basic Authentication (`admin:admin`).
- **Asynchronous Telemetry Streaming (WAN / MQTT)**: Ingests high-frequency real-time verification logs (`VerifyPush`), stranger snapshots (`SnapPush`), behavioral infractions, and device heartbeats via an MQTT broker subscribing to camera topic streams (`mqtt/face/<DeviceID>/*`).
- **Real-Time Client Broadcasting**: Distributes ingested telemetry events to live web dashboards via WebSockets with minimal latency.

---

## 2. Technology Stack

| Layer | Technology | Version / Specification | Role in System |
| :--- | :--- | :--- | :--- |
| **Backend Framework** | Laravel | PHP 8.2+ / Laravel 11+ | REST APIs, authentication, job orchestration, business logic |
| **Queue & Monitoring** | Laravel Horizon | Redis-backed | Asynchronous worker pool for device provisioning (`camera-sync`) |
| **Frontend Framework** | Vue 3 | Composition API, Vite, Pinia | Live streaming dashboard, device manager, personnel CRUD UI |
| **Styling & UI** | Tailwind CSS / Shadcn Vue | Modern dark/light design | Responsive, high-density analytics interface |
| **Primary Database** | PostgreSQL | 16+ | Relational data: devices, personnel, access logs, sync tasks |
| **Cache & Key-Value** | Redis | 7.x+ | Job queues, Horizon telemetry state, distributed locks, caching |
| **MQTT Ingestion Broker**| EMQX / Mosquitto | MQTT v3.1.1 (QoS 0) | Ingests real-time camera events, alarms, and heartbeat packets |
| **MQTT Ingestion Daemon**| `php-mqtt/client` | Long-running CLI worker | Persistent daemon listening to MQTT topics and dispatching events |
| **Real-Time Broadcasting**| Laravel Reverb | WebSockets (Pusher protocol) | Live push of access and alert events directly to Vue 3 UI |
| **Process Supervision** | Supervisord / Systemd | Linux daemon manager | Manages `queue:work`, `horizon`, and `mqtt:listen` processes |

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
        LANWorker["LAN Edge Sync Worker\n(Queue: camera-sync)"]
        MQTTDaemon["MQTT Telemetry Daemon\n(php artisan mqtt:listen)"]
    end

    subgraph Broker["Message Broker"]
        MQTTBroker["MQTT Broker (EMQX / Mosquitto :1883)"]
    end

    subgraph EdgeDevices["Edge Camera Infrastructure"]
        Camera["Intelligent AI Camera (X40Y)\nIP: 192.168.1.100:8080"]
    end

    UI <-->|HTTP REST / Auth| API
    UI <-->|WebSocket Stream| Reverb
    API <--> DB
    API <--> RedisCache

    API -->|Dispatch Sync Jobs| RedisCache
    RedisCache --> Horizon
    Horizon --> LANWorker

    LANWorker -->|HTTP POST /action/*\nBasic Auth| Camera

    Camera -->|MQTT Pub: mqtt/face/{ID}/*| MQTTBroker
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
    ip_address VARCHAR(45) NOT NULL,             -- e.g., '192.168.1.100'
    port INT DEFAULT 8080,
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
- **Camera Management Service (`App\Services\CameraService`)**: Formulates JSON payloads and sends HTTP POST requests to camera hardware endpoints using `Illuminate\Support\Facades\Http` with HTTP Basic Authentication.
- **Personnel Sync Observer (`App\Observers\PersonnelObserver`)**: Automatically dispatches `SyncPersonnelToDeviceJob` to the `camera-sync` Redis queue whenever a personnel record or facial image is created, updated, or removed.
- **Storage Manager (`App\Services\ImageStorageService`)**: Ingests Base64 image payloads received from MQTT/Webhooks, saves binaries to local disk or S3/R2 storage, and generates public storage URLs for the UI.

### B. LAN Dispatcher (Edge Sync Worker)
- Runs as a dedicated Redis queue worker: `php artisan queue:work redis --queue=camera-sync`
- Dispatches personnel sync operations (`/action/EditPersonNew`, `/action/AddPersons`, `/action/DeletePerson`) to cameras on the local network (`http://192.168.1.100:8080`).
- Implements exponential backoff, rate limiting (&ge; 1s between single calls), and error logging using camera status codes.

### C. Cloud MQTT Telemetry Daemon
- Long-running Laravel CLI command: `php artisan mqtt:listen` powered by `php-mqtt/client`.
- Subscribes to configured camera wildcard topics (e.g. `mqtt/face/+/Rec`, `mqtt/face/+/Snap`, `mqtt/face/heartbeat`).
- **Event Handling**:
  - `RecPush`: Extracts `customId`, `VerifyStatus`, `similarity1`, saves to `access_logs`, and fires `AccessLogReceived` event over Laravel Reverb.
  - `StrSnapPush`: Extracts snapshot images, saves to `stranger_snaps`, and notifies UI.
  - `HeartBeat`: Updates `devices.last_heartbeat_at`.
  - Sends MQTT `PushAck` confirmation packets when continuous transmission is enabled.

### D. Vue 3 Real-Time Frontend
- **Live Event Monitor**: Real-time access log feed displaying matched photo, employee name, similarity percentage, admission status badge (Allowed / Denied), and timestamps via WebSockets.
- **Personnel Directory**: Manage users, upload/crop facial images, set temporary/permanent access schedules, and trigger manual syncs.
- **Device Management Panel**: Configure camera network parameters, push MQTT settings (`SetMQTTParam`), view online/offline statuses, and trigger remote reboots (`RebootDevice`).

---

## 6. Camera Protocol Reference Mapping

| Operation | Protocol / Endpoint | Channel | Key Payload Parameters |
| :--- | :--- | :--- | :--- |
| **Add / Update Person** | `POST /action/EditPersonNew` | HTTP (LAN) | `DeviceID`, `IdType: 0`, `CustomizeID`, `Name`, `PersonType`, `picinfo` / `picURI` |
| **Batch Add Persons** | `POST /action/AddPersons` | HTTP (LAN) | `DeviceID`, `Total`, `Personinfo_0: {...}` (up to 32 Base64 / 1000 URI) |
| **Delete Person** | `POST /action/DeletePerson` | HTTP (LAN) | `DeviceID`, `TotalNum`, `IdType: 0`, `CustomizeID: [id1, id2]` |
| **Delete All Persons** | `POST /action/DeleteAllPerson` | HTTP (LAN) | `DeleteAllPersonCheck: 1` *(Triggers auto-reboot)* |
| **Search List** | `POST /action/SearchPersonList` | HTTP (LAN) | `DeviceID`, `PersonType: 2`, `BeginNO: 0`, `RequestCount: 50` |
| **Configure MQTT** | `POST /action/SetMQTTParam` | HTTP (LAN) | `MQEnable: 1`, `MQAddr`, `MQPort`, `MQTopic`, `RecordUploadType: 1` |
| **Reboot Camera** | `POST /action/RebootDevice` | HTTP (LAN) | `DeviceID`, `IsRebootDevice: 1` |
| **Live Verification Stream** | `mqtt/face/{DeviceID}/Rec` | MQTT (Broker) | `VerifyPush` (`VerifyStatus`, `similarity1`, `pic`, `scene`) |
| **Stranger Alert Stream** | `mqtt/face/{DeviceID}/Snap` | MQTT (Broker) | `StrSnapPush` (`CreateTime`, `pic`, `scene`) |
| **Heartbeat Stream** | `mqtt/face/heartbeat` | MQTT (Broker) | `HeartBeat` (`facesluiceId`, `time`) |

---

## 7. Execution & Deployment Checklist

1. **Database Setup**:
   - Run PostgreSQL 16 migrations to establish tables: `devices`, `personnel`, `access_logs`, `stranger_snaps`, `sync_tasks`.
2. **MQTT Broker Configuration**:
   - Launch EMQX or Mosquitto on port `1883`.
   - Configure authentication credentials for the cameras and Laravel daemon.
3. **Camera Initialization**:
   - Send HTTP POST `/action/SetMQTTParam` to the camera (`192.168.1.100:8080`) with the broker's IP, port, and topics.
4. **Daemon Deployment (Supervisord)**:
   - Configure supervisor programs for:
     - `php artisan horizon` (or `php artisan queue:work --queue=camera-sync`)
     - `php artisan mqtt:listen`
     - `php artisan reverb:start`
5. **Frontend Launch**:
   - Build or serve Vue 3 application with Vite and configure WebSocket connection pointing to Laravel Reverb.
