# Intelligent Network Camera MQTT Protocol (V1.25) - Technical Specification & Integration Context

> **Vendor / Manufacturer:** Chongqing Huifan Technology Co., Ltd. / Shenzhen Bio Technology Co., Ltd. (HFSecurity)  
> **Protocol Document:** MQTT Protocol of Intelligent Network Camera V1.25  
> **Target Devices:** Face Recognition & Intelligent Edge AI Cameras (IPC / Terminal Units)

---

## Table of Contents

1. [Architectural Overview & Communication Principles](#1-architectural-overview--communication-principles)
   - [Protocol Standard & Settings](#11-protocol-standard--settings)
   - [Topic Architecture & Routing Convention](#12-topic-architecture--routing-convention)
   - [Standard Request & Acknowledgement Schema](#13-standard-request--acknowledgement-schema)
2. [Device Lifecycle & Status Notifications](#2-device-lifecycle--status-notifications)
   - [Online Notification (`Online` / `Online-Ack`)](#21-device-online-notification)
   - [Offline Notification & Last Will (`Offline`)](#22-device-offline-notification--last-will)
   - [Periodic Heartbeat (`HeartBeat`)](#23-periodic-heartbeat)
   - [Continuous Transmission & Reliability Mechanism (`PushAck` / `PushAck-Ack`)](#24-continuous-transmission--reliability-mechanism)
3. [Personnel List Management (Face Database via MQTT)](#3-personnel-list-management-face-database-via-mqtt)
   - [Add or Modify Single Person (`EditPerson`)](#31-add-or-modify-single-person-editperson)
   - [Batch Addition of Personnel via URI (`AddPersons`)](#32-batch-addition-of-personnel-via-uri-addpersons)
   - [Batch Add/Modify Personnel via URI (`EditPersonsNew`)](#33-batch-addmodify-personnel-via-uri-editpersonsnew)
   - [Query Personnel (`QueryPerson`, `SearchPerson`, `SearchPersonList`)](#34-query-personnel)
   - [Delete Personnel (`DelPerson`, `DeletePersons`, `DeleteAllPerson`)](#35-delete-personnel)
4. [Push Events & AI Analytics Uploads (Camera -> Broker)](#4-push-events--ai-analytics-uploads-camera---broker)
   - [Personnel Face Identification Record (`RecPush`)](#41-personnel-face-identification-record-recpush)
   - [Stranger Snapshot Record (`StrSnapPush`)](#42-stranger-snapshot-record-strsnappush)
   - [Visible Kitchen Hygiene & Safety Alarms (`VisibleKitchenSnapPush`)](#43-visible-kitchen-hygiene--safety-alarms-visiblekitchensnappush)
   - [Objects Dropped from Height (`ParabolicSnapPush`)](#44-objects-dropped-from-height-parabolicsnappush)
   - [Thermal Imaging & Spark Alarm (`TemHighSnapPush`)](#45-thermal-imaging--spark-alarm-temhighsnappush)
   - [Structured Human & Vehicle Capture (`FullStrSnapPush`)](#46-structured-human--vehicle-capture-fullstrsnappush)
   - [Behavioral Analysis: Line & Area Crossing (`BehaviorSnapPush`)](#47-behavioral-analysis-line--area-crossing-behaviorsnappush)
   - [Leave Post / Absence from Work Monitoring (`LeaveSnapPush`)](#48-leave-post--absence-from-work-monitoring-leavesnappush)
   - [PPE: Helmet & Reflective Vest Detection (`ClothHelmetSnapPush`)](#49-ppe-helmet--reflective-vest-detection-clothhelmetsnappush)
   - [Pyrotechnics: Fire & Smoke Detection (`FireSmokeSnapPush`)](#410-pyrotechnics-fire--smoke-detection-firesmokesnappush)
   - [Safe Riding / Electric Bicycle Detection (`SafetyRidePush` / `ElectricalBicycleSnapPush`)](#411-safe-riding--electric-bicycle-detection)
   - [Electric Spark & Extreme Temperature (`TemHighSnapPush`)](#412-electric-spark--extreme-temperature-temhighsnappush)
   - [Regional Foot Traffic & Head Count (`CountInRegionPush`)](#413-regional-foot-traffic--head-count-countinregionpush)
   - [Vehicle Attributes Analysis (`CatAttrSnapPush`)](#414-vehicle-attributes-analysis-catattrsnappush)
   - [Manual Control Log Push (`ManualPushRecords` / `ManualPushSnaps`)](#415-manual-control-log-push)
5. [Device Configuration, Maintenance & AI Rules](#5-device-configuration-maintenance--ai-rules)
   - [MQTT Reporting Parameters (`GetMQTTconfig` / `UpMQTTconfig`)](#51-mqtt-reporting-parameters)
   - [Sound & Display Settings (`GetSoundconfig` / `UpSoundconfig`)](#52-sound--display-settings)
   - [System Time Synchronization (`GetSysTime` / `SetSysTime`)](#53-system-time-synchronization)
   - [RTSP Configuration (`GetRTSPCfg` / `SetRTSPCfg`)](#54-rtsp-configuration)
   - [Device Information & 4G SIM Status (`GetDeviceInformation` / `QuerySIMCardInfo`)](#55-device-information--4g-sim-status)
   - [Face Defense Area & Algorithm Boundary (`GetDeploymentRulesconfig` / `SetDeploymentRulesconfig`)](#56-face-defense-area--algorithm-boundary)
   - [Tripwire Intrusion Rules (`GetLineDetectionConfig` / `SetLineDetectionConfig`)](#57-tripwire-intrusion-rules)
   - [Field / Area Intrusion Rules (`GetFieldDetectionConfig` / `SetFieldDetectionConfig`)](#58-field--area-intrusion-rules)
   - [On-Demand Scene Capture (`GetSceneSnap`)](#59-on-demand-scene-capture)
   - [Passage Statistics / Target Counts (`GetCount`, `TargetCountStatsCfg`, `TargetResetCount`)](#510-passage-statistics--target-counts)
   - [Remote Device Reboot, Factory Reset & Firmware Upgrade](#511-remote-device-reboot-factory-reset--firmware-upgrade)
6. [Complete Error Code Reference](#6-complete-error-code-reference)

---

## 1. Architectural Overview & Communication Principles

### 1.1 Protocol Standard & Settings
- **Protocol**: MQTT v3.1.1
- **Quality of Service (QoS)**: QoS 0 (Reliability is managed via application-level ACK packets when breakpoint resume is enabled).
- **Payload Format**: Standard JSON encoded in UTF-8.
- **Image Data**: Base64 encoded string (`pic` / `scene`) within 1MB (2MB for scene images) or HTTP/HTTPS download URL (`picURI`).

### 1.2 Topic Architecture & Routing Convention

Let `<DeviceID>` (or `<facesluiceId>`) be the unique camera identifier (e.g. `005a213b000b93cc` or `1299523`).

| Channel Purpose | Topic Format | Publisher | Subscriber | Description |
| :--- | :--- | :--- | :--- | :--- |
| **Command Channel** | `mqtt/face/<DeviceID>` | Cloud Server | Camera | Cloud publishes operational instructions & configuration |
| **Command Response (ACK)** | `mqtt/face/<DeviceID>/Ack` | Camera | Cloud Server | Camera responds with command execution result |
| **Identification Events** | `mqtt/face/<DeviceID>/Rec` | Camera | Cloud Server | Whitelist / blacklist face verification logs |
| **Stranger / Alarm Snaps** | `mqtt/face/<DeviceID>/Snap` | Camera | Cloud Server | Unregistered faces, AI violation snapshots |
| **System Status & LWT** | `mqtt/face/basic` | Camera / Broker | Cloud Server | Online announcements & Last Will and Testament (LWT) |
| **Heartbeat Stream** | `mqtt/face/heartbeat` | Camera | Cloud Server | 30-second periodic heartbeat keepalive |

---

### 1.3 Standard Request & Acknowledgement Schema

#### Downlink Instruction (Server &rarr; Camera on `mqtt/face/<DeviceID>`):
```json
{
  "messageId": "MSG-20240822-0001",
  "operator": "<OperatorName>",
  "info": {
    "facesluiceId": "005a213b000b93cc",
    "...": "..."
  }
}
```

#### Uplink Response (Camera &rarr; Server on `mqtt/face/<DeviceID>/Ack`):
```json
{
  "messageId": "MSG-20240822-0001",
  "operator": "<OperatorName>-Ack",
  "code": "200",
  "info": {
    "facesluiceId": "005a213b000b93cc",
    "result": "ok",
    "detail": ""
  }
}
```

---

## 2. Device Lifecycle & Status Notifications

### 2.1 Device Online Notification
When the camera connects to the MQTT broker, it publishes to `mqtt/face/basic`:
```json
{
  "operator": "Online",
  "info": {
    "facesluiceId": "005a213b000b93cc",
    "username": "admin",
    "time": "2024-08-22 15:11:10",
    "ip": "192.168.2.202",
    "facesname": "Entrance_Camera_01"
  }
}
```
**Required Server Response** (Server publishes to `mqtt/face/basic` or `mqtt/face/<DeviceID>`):
```json
{
  "messageId": "10201",
  "operator": "Online-Ack",
  "info": {
    "facesluiceId": "005a213b000b93cc",
    "result": "ok"
  }
}
```
*Note: If no ACK is received, the camera will re-send the Online notification periodically.*

### 2.2 Device Offline Notification & Last Will
Configured as the MQTT Last Will and Testament (LWT) on `mqtt/face/basic`:
```json
{
  "operator": "Offline",
  "info": {
    "facesluiceId": "005a213b000b93cc"
  }
}
```

### 2.3 Periodic Heartbeat
Camera publishes every 30 seconds to `mqtt/face/heartbeat`:
```json
{
  "operator": "HeartBeat",
  "info": {
    "facesluiceId": "005a213b000b93cc",
    "time": "2024-08-22 19:43:34"
  }
}
```

---

### 2.4 Continuous Transmission & Reliability Mechanism

When `ResumefromBreakpoint = 1` is enabled on the camera:
1. When sending `RecPush`, `StrSnapPush`, or generic snapshots, the camera expects the platform to publish a `PushAck` confirmation within **10 seconds**.
2. If no confirmation is received within 10 seconds, the camera re-pushes the same record.

#### Push Confirmation Schema:
- **Server Confirmation** (Publish to `mqtt/face/<DeviceID>`):
  - `PushAckType`: `1`: Stranger snapshot (`SnapID`), `2`: Identification record (`RecordID`), `3`: Generic snapshot (`SnapOrRecordID`).
```json
{
  "operator": "PushAck",
  "messageId": "MSG-ACK-1001",
  "info": {
    "PushAckType": 2,
    "SnapOrRecordID": 490805
  }
}
```
- **Camera Confirmation ACK** (Publish to `mqtt/face/<DeviceID>/Ack`):
```json
{
  "messageId": "MSG-ACK-1001",
  "operator": "PushAck-Ack",
  "info": {
    "facesluiceId": "005a213b000b93cc",
    "result": "ok",
    "PushAckType": "2",
    "SnapOrRecordID": "490805"
  }
}
```

---

## 3. Personnel List Management (Face Database via MQTT)

### 3.1 Add or Modify Single Person (`EditPerson`)
- **Downlink Topic**: `mqtt/face/<DeviceID>`
- **Behavior**: If `customId` already exists on the device, it updates the record; otherwise, it inserts a new person.
- **Interval Warning**: Keep calls spaced &ge; 1 sec apart (Base64 mode) or &ge; 2 sec apart (URI mode) to prevent message queue starvation.

```json
{
  "messageId": "EDIT-001",
  "operator": "EditPerson",
  "info": {
    "customId": "EMP10001",
    "name": "Sarah Connor",
    "gender": 1,
    "birthday": "1995-06-12",
    "address": "Tech District, Unit 4B",
    "idCard": "421381199504030002",
    "telnum1": "18888888888",
    "personType": 0,
    "tempValid": 0,
    "validBegin": "2024-01-01 00:00:00",
    "validEnd": "2025-12-31 00:00:00",
    "effectNumber": 10000,
    "picURI": "https://cdn.example.com/faces/sarah.jpg"
  }
}
```

---

### 3.2 Batch Addition of Personnel via URI (`AddPersons`)
- Supports adding up to **1000 persons** in a single packet via download URLs.
- Uses envelope boundary markers `DataBegin: "BeginFlag"` and `DataEnd: "EndFlag"`.
- `isCheckSimilarity`: `0`: Skip similarity check, `1`: Check against existing face database (rejects if too similar).

```json
{
  "messageId": "BATCH-ADD-20240822-001",
  "DataBegin": "BeginFlag",
  "operator": "AddPersons",
  "PersonNum": 2,
  "info": [
    {
      "customId": "EMP10001",
      "name": "Alice",
      "gender": 1,
      "birthday": "1992-06-13",
      "address": "Building 1",
      "idCard": "42138119920613001",
      "telnum1": "13690880001",
      "personType": 0,
      "picURI": "https://cdn.example.com/faces/alice.jpg",
      "tempValid": 0,
      "validBegin": "2024-01-01 00:00:00",
      "validEnd": "2025-12-31 00:00:00",
      "effectNumber": 10000
    },
    {
      "customId": "EMP10002",
      "name": "Bob",
      "gender": 0,
      "birthday": "1990-11-04",
      "address": "Building 2",
      "idCard": "42138119901104002",
      "telnum1": "13690880002",
      "personType": 0,
      "picURI": "https://cdn.example.com/faces/bob.jpg",
      "tempValid": 0,
      "validBegin": "2024-01-01 00:00:00",
      "validEnd": "2025-12-31 00:00:00",
      "effectNumber": 10000
    }
  ],
  "DataEnd": "EndFlag"
}
```

**Camera Response (`AddPersons-Ack`):**
```json
{
  "messageId": "BATCH-ADD-20240822-001",
  "operator": "AddPersons-Ack",
  "info": {
    "facesluiceId": "005a213b000b93cc",
    "AddErrNum": "0",
    "AddSucNum": "2",
    "AddErrInfo": [],
    "AddSucInfo": [
      {"customId": "EMP10001"},
      {"customId": "EMP10002"}
    ],
    "result": "ok"
  }
}
```

---

### 3.3 Batch Add/Modify Personnel via URI (`EditPersonsNew`)
Similar to `AddPersons`, but automatically modifies existing records if `customId` exists, or inserts them if not.

---

### 3.4 Query Personnel

1. **Query All `customId`s (`QueryPerson`)**:
   - Request: `{"operator": "QueryPerson", "messageId": "Q1", "info": {}}`
   - Response: Returns comma-separated list of all stored `customId`s, `totalPersonNum`, and `queryPersonNum`.

2. **Query Single Person Detail (`SearchPerson`)**:
   - Request: `{"operator": "SearchPerson", "messageId": "Q2", "info": {"customId": "EMP10001", "Picture": 1}}`
   - Response: Returns person metadata and base64 face picture.

3. **Multi-Person Filter & Pagination (`SearchPersonList`)**:
   - Supports filtering by `BeginTime`, `EndTime`, `name` (fuzzy search), `gender` (`0`: Male, `1`: Female, `2`: All), and paging via `BeginNO` and `RequestCount` (Max `2000`).

---

### 3.5 Delete Personnel

1. **Delete Single Person (`DelPerson`)**:
   ```json
   {
     "operator": "DelPerson",
     "messageId": "DEL-1",
     "info": {
       "customId": "EMP10001"
     }
   }
   ```

2. **Batch Delete Persons (`DeletePersons`)** (Up to 200 IDs at once):
   ```json
   {
     "messageId": "BATCH-DEL-1",
     "DataBegin": "BeginFlag",
     "operator": "DeletePersons",
     "PersonNum": 2,
     "info": {
       "customId": ["EMP10001", "EMP10002"]
     },
     "DataEnd": "EndFlag"
   }
   ```

3. **Delete All Personnel (`DeleteAllPerson`)**:
   ```json
   {
     "messageId": "DEL-ALL-1",
     "operator": "DeleteAllPerson",
     "info": {
       "deleteall": 1
     }
   }
   ```

---

## 4. Push Events & AI Analytics Uploads (Camera -> Broker)

### 4.1 Personnel Face Identification Record (`RecPush`)
Pushed to `mqtt/face/<DeviceID>/Rec`:
```json
{
  "operator": "RecPush",
  "info": {
    "customId": "EMP10001",
    "personId": "22",
    "RecordID": "389",
    "VerifyStatus": "1",
    "PersonType": "0",
    "similarity1": "94.000000",
    "Sendintime": 1,
    "personName": "Sarah Connor",
    "facesluiceId": "005a213b000b93cc",
    "idCard": "421381199504030002",
    "telnum": "18888888888",
    "time": "2024-08-22 13:33:33",
    "isNoMask": "0",
    "PushType": 0,
    "targetPosInScene": [217, 0, 1333, 1080],
    "pic": "data:image/jpeg;base64,...",
    "scene": "data:image/jpeg;base64,..."
  }
}
```
*`VerifyStatus`: `0`: None, `1`: Allowed, `2`: Refused / Blacklist, `3`: Not registered.*  
*`isNoMask`: `0`: Mask on / disabled, `1`: Mask missing, `2`: Mask missing (permitted under rule).*

### 4.2 Stranger Snapshot Record (`StrSnapPush`)
Pushed to `mqtt/face/<DeviceID>/Snap`:
```json
{
  "operator": "StrSnapPush",
  "info": {
    "facesluiceId": "005a213b000b93cc",
    "SnapID": "946",
    "time": "2024-08-22 12:00:30",
    "isNoMask": "1",
    "PushType": 0,
    "targetPosInScene": [0, 0, 1161, 1080],
    "pic": "data:image/jpeg;base64,...",
    "scene": "data:image/jpeg;base64,..."
  }
}
```

### 4.3 Visible Kitchen Hygiene & Safety Alarms (`VisibleKitchenSnapPush`)
- `targetType`: `0`: Cook, `1`: Animal, `2`: Object / Dumpster.
- `CookAlarmType`: `0`: No chef hat, `1`: No mask, `2`: No uniform, `3`: Smoking, `4`: Phone call, `5`: Bare shoulders.
- `AnimalAlarmType`: `0`: Cat, `1`: Dog, `2`: Mouse/Rat.
- `ObjectAlarmType`: `0`: Uncovered dumpster.

### 4.4 Objects Dropped from Height (`ParabolicSnapPush`)
```json
{
  "operator": "ParabolicSnapPush",
  "info": {
    "deviceId": "005a213b000b93cc",
    "deviceName": "ipc758732",
    "snapTime": "2024-08-22 17:18:40",
    "startTime": "2024-08-22 17:18:38",
    "endTime": "2024-08-22 17:18:42",
    "pic": "data:image/jpeg;base64,..."
  }
}
```

### 4.5 Thermal Imaging & Spark Alarm (`TemHighSnapPush`)
```json
{
  "operator": "TemHighSnapPush",
  "info": {
    "deviceId": "005a213b000b93cc",
    "deviceName": "IPC758732",
    "snapTime": "2024-08-22 11:01:02",
    "sparkAlarm": 0,
    "message": [
      {
        "areaId": 0,
        "messageType": 0,
        "alarmType": 1,
        "temRise": 0,
        "durationTime": 30,
        "temRiseDuration": 5,
        "temperature": 55.4
      }
    ],
    "pic": "data:image/jpeg;base64,...",
    "tempic": "data:image/jpeg;base64,..."
  }
}
```

### 4.6 Structured Human & Vehicle Capture (`FullStrSnapPush`)
- `objectType`: `0`: Face, `1`: Human Body, `2`: Motor Vehicle (`objectAttr.carplate`), `3`: Non-motor vehicle.

### 4.7 Behavioral Analysis: Line & Area Crossing (`BehaviorSnapPush`)
- `objectType`: `0`: Human, `1`: Vehicle.
- `behaviourType`: `0`: Tripwire incursion, `1`: Area incursion.
- `behaviourDirection`: `0`: Left to right, `1`: Right to left, `2`: Enter area, `3`: Leave area.

### 4.8 Leave Post / Absence from Work Monitoring (`LeaveSnapPush`)
Reports guard / operator absence from their assigned station (`time`, `deviceId`, `pic`).

### 4.9 PPE: Helmet & Reflective Vest Detection (`ClothHelmetSnapPush`)
- `helmet`: `0`: Wearing, `1`: Not wearing, `2`: Not sure.
- `reflectiveVest`: `0`: Wearing, `1`: Not wearing, `2`: Not sure.

### 4.10 Pyrotechnics: Fire & Smoke Detection (`FireSmokeSnapPush`)
- `fire`: `0`: None, `1`: Present, `2`: Not sure.
- `smoke`: `0`: None, `1`: Present, `2`: Not sure.

### 4.11 Safe Riding / Electric Bicycle Detection
- `SafetyRidePush`: Checks helmet wearing on e-bikes (`helmet: 0` No, `1` Yes, `plate`).
- `ElectricalBicycleSnapPush`: General electric bicycle detection event (`SnapOrRecordID`, `pic`, `scene`).

### 4.12 Electric Spark & Extreme Temperature (`TemHighSnapPush`)
- Triple-spectral & dual-spectral sensor reporting (`sparkAlarm: 2` Spark-only mode, `tempeunit: 0` Celsius / `1` Fahrenheit).

### 4.13 Regional Foot Traffic & Head Count (`CountInRegionPush`)
- Reports `realtimeCount`, `averageCount`, `maxCount`, and `minCount`.

### 4.14 Vehicle Attributes Analysis (`CatAttrSnapPush`)
- Provides in-depth attributes: `carColor` (0-13), `carClass` (15+ types), `carSpecial` (emergency/commercial codes), `carViewpoint`, `carBackup`, `carplate`, `carplateColor`, `carplateType`, `carplateFlag` (plate obstruction detection).

### 4.15 Manual Control Log Push
- `ManualPushRecords`: Force re-stream identification logs within `[TimeS, TimeE]`.
- `ManualPushSnaps`: Force re-stream stranger logs within `[TimeS, TimeE]`.

---

## 5. Device Configuration, Maintenance & AI Rules

### 5.1 MQTT Reporting Parameters
- **Query**: `GetMQTTconfig`
- **Update**: `UpMQTTconfig`
```json
{
  "operator": "UpMQTTconfig",
  "messageId": "CFG-001",
  "info": {
    "facesluiceId": "005a213b000b93cc",
    "StrangerUploadType": 0,
    "RecordUploadType": 1,
    "BehaviorUploadType": 255,
    "KeepAliveInterval": 30,
    "OnlineTopic": "mqtt/face/basic",
    "HeartbeatTopic": "mqtt/face/heartbeat",
    "ResumefromBreakpoint": 1,
    "BeginTime": "2024-01-01 00:00:00"
  }
}
```

### 5.2 Sound & Display Settings
- `GetSoundconfig` / `UpSoundconfig`: Configure `VerifySuccAudio` (`0`: Off, `1`: Voice broadcast) and `Volume` (`0~100`).

### 5.3 System Time Synchronization
- `GetSysTime` / `SetSysTime`: Set device clock format `YYYY-MM-DD hh:mm:ss`.

### 5.4 RTSP Configuration
- `GetRTSPCfg` / `SetRTSPCfg`: Toggle RTSP stream service (`OpenVerify: 0/1`), port (`RTSPPort`, default 554), and `PackSize` (1500 bytes). *Setting RTSP reboots the camera.*

### 5.5 Device Information & 4G SIM Status
- `GetDeviceInformation`: Returns `DeviceType` (`0`: IPC, `1`: DVR, `2`: NVR, `3`: Panel Unit).
- `QuerySIMCardInfo`: Returns `imei`, `iccid`, and `imsi` for cellular-enabled cameras.

### 5.6 Face Defense Area & Algorithm Boundary
- `GetDeploymentRulesconfig` / `SetDeploymentRulesconfig`: Configures 2D polygon detection boundaries and min/max detection box sizes normalized on a `10000 x 10000` grid coordinate scale.

### 5.7 Tripwire Intrusion Rules
- `GetLineDetectionConfig` / `SetLineDetectionConfig`:
  - Up to 4 tripwires (`scale: 10000`).
  - `crossDirection`: `0`: Bidirectional, `1`: A &rarr; B, `2`: A &larr; B.
  - Weekly schedule `timeConf` (`day: 0~6` Sunday to Saturday, time spans).
  - Linkage actions: `audio` (buzzer/voice), `ioOut` (alarm relay duration & pulse), `light` (strobe warning), `mail`, `ftp`.

### 5.8 Field / Area Intrusion Rules
- `GetFieldDetectionConfig` / `SetFieldDetectionConfig`: Up to 4 polygon zones with schedule and alarm linkage outputs.

### 5.9 On-Demand Scene Capture
- `GetSceneSnap`: Requests real-time snapshot (`ImgType: 2` JPEG, `ImgQuality: 55~100`). Returns base64 image `ImgData`.

### 5.10 Passage Statistics / Target Counts
- `GetCount`: Query live counts for human / vehicle in a given direction.
- `TargetCountStatsCfg`: Configure automatic resetting (Daily, Weekly, Monthly at specified time).
- `TargetResetCount`: Manually reset live counters to zero.

### 5.11 Remote Device Reboot, Factory Reset & Firmware Upgrade
- **Reboot**: `RebootDevice` with `{"info": {}}`.
- **Factory Reset**: `SetFactoryDefault` (`DefaltNetPar: 0/1`, `DefaltPerson: 0/1`).
- **Firmware Upgrade**: `Upgrade` with `upgradeType: 1` and `path: "https://.../update.udx"`. Query version with `Versions`.

---

## 6. Complete Error Code Reference

### 6.1 General Command & Batch Return Codes
| Code | Error String | Meaning |
| :--- | :--- | :--- |
| `200` | Successful | Command executed successfully |
| `410` | `AddPersons / EditPersonsNew is busy` | Previous batch operation still executing |
| `412` | `can not find DataBegin` | Missing `"DataBegin": "BeginFlag"` |
| `413` | `can not find DataEnd` | Missing `"DataEnd": "EndFlag"` |
| `414` | `Parameter error` | Missing root `"info"` object |
| `415` | `can not find PersonNum` | Missing `PersonNum` |
| `416` | `PersonNum out of range` | Exceeds batch limit (1000 for add, 200 for delete) |
| `417` | `json of person's data is not equal PersonNum` | Array length mismatch |
| `460` | `Data out of range` | Single packet payload exceeds 1MB |
| `461` | `can't find customId` | Missing or unrecognized `customId` |
| `462` | `Parameter error` | General parameter error |
| `463` | `pic base64 data decode err` / `Unknow deviceId` | Invalid base64 or device ID mismatch |
| `464` | `Get pic Person Feature err` / `Unknow TimeS` | Face feature extraction failed / TimeS missing |
| `465` | `Database operation failed` / `Unknow TimeE` | Database error / TimeE missing |
| `467` | `Person num is full` / `Unknow RecordUploadType` | Face database full (quota reached) |
| `468` | `get URI pic data len too short` | Downloaded face image &lt; 1000 bytes |
| `469` | `get URI pic data len too long` | Downloaded image &gt; 1MB |
| `470` | `get URI server ip error` | Failed to resolve DNS / server IP for image URL |
| `471` | `get pic and connect URI IP error` | Connection timeout downloading image |
| `472` | `get pic and get URI error` | Neither `pic` nor `picURI` provided |
| `474` | `Device list is full` | Local storage full |
| `478` | `Similarity too high` | Image is too similar to an existing enrolled person |
| `479` | `Failed to modify person list` | Local database update failed |
| `482` | `Failed to read pictures of existing people` | Internal face file read error |
| `11000`| `Shoot one picture fail` | Scene snapshot capture hardware failure |
| `22000-22004` | `TargetCountStatsCfg fail` | Foot traffic statistics configuration failure |
| `23000`| `Set TargetResetCount fail` | Count reset execution failure |
