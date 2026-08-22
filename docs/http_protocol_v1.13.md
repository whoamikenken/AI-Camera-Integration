# Intelligent Network Camera HTTP Protocol (V1.13) - Technical Specification & Integration Context

> **Vendor / Manufacturer:** Chongqing Huifan Technology Co., LTD (HFSecurity)  
> **Protocol Document:** HTTP Protocol of Intelligent Network Camera V1.13  
> **Protocol Target:** Face Recognition & Intelligent AI Camera Devices  

---

## Table of Contents

1. [Architectural Overview & Communication Principles](#1-architectural-overview--communication-principles)
   - [Protocol Basics](#11-protocol-basics)
   - [Request Grammar & Addressing](#12-request-grammar--addressing)
   - [Authentication](#13-authentication)
   - [Standard Request & Response Envelopes](#14-standard-request--response-envelopes)
2. [Personnel List Management (Face Database)](#2-personnel-list-management-face-database)
   - [Data Structures & Fields](#21-data-structures--fields)
   - [Add Multiple Persons (`AddPersons`)](#22-add-multiple-persons-addpersons)
   - [Batch Add/Modify Persons (`EditPersonsNew`)](#23-batch-addmodify-persons-editpersonsnew)
   - [Add or Modify Single Person (`EditPersonNew`)](#24-add-or-modify-single-person-editpersonnew)
   - [Delete Single/Multiple Persons (`DeletePerson`)](#25-delete-singlemultiple-persons-deleteperson)
   - [Delete All Persons (`DeleteAllPerson`)](#26-delete-all-persons-deleteallperson)
   - [Query Single Person (`SearchPerson`)](#27-query-single-person-searchperson)
   - [Query Total Person Count (`SearchPersonNum`)](#28-query-total-person-count-searchpersonnum)
   - [Query Multi-Person List / Search (`SearchPersonList`)](#29-query-multi-person-list--search-searchpersonlist)
3. [Event Subscriptions & Push Notifications (Webhooks)](#3-event-subscriptions--push-notifications-webhooks)
   - [Subscription Management (`Subscribe` / `Unsubscribe` / `GetSubscribe`)](#31-subscription-management)
   - [Subscription Heartbeat (`HeartBeat`)](#32-subscription-heartbeat)
   - [Push Events (Camera -> Server Webhooks)](#33-push-events-camera---server-webhooks)
     - [Stranger Capture Push (`SnapPush`)](#331-stranger-capture-push-snappush)
     - [Verification / Recognition Result (`VerifyPush`)](#332-verification--recognition-result-verifypush)
     - [Intelligent Violation Alarm (`SnapPush` with `AlarmAction`)](#333-intelligent-violation-alarm)
     - [Objects Dropped from Height (`ParabolicSnapPush` & PushVideo)](#334-objects-dropped-from-height)
     - [Thermal Imaging & Spark Alarm (`TemHighSnapPush`)](#335-thermal-imaging--spark-alarm)
     - [Personnel & Vehicular Capture (`FullStrSnapPush`)](#336-personnel--vehicular-capture)
     - [Behavioral Analysis (`BehaviorSnapPush`)](#337-behavioral-analysis)
     - [Absence from Post / Work Monitoring (`LeaveSnapPush`)](#338-absence-from-post--work-monitoring)
     - [License Plate Recognition (`PlateSnapPush`)](#339-license-plate-recognition)
     - [Regional Headcount (`CountInRegionPush`)](#3310-regional-headcount)
     - [Vehicle Attributes (`CatAttrSnapPush`)](#3311-vehicle-attributes)
     - [Vehicle Washing / Flushing Inspection](#3312-vehicle-washing--flushing-inspection)
     - [PPE Violation: Helmet & Reflective Vest (`ClothHelmetVerifyPush`)](#3313-ppe-violation-helmet--reflective-vest)
     - [Fire & Smoke / Pyrotechnics Detection (`ClothHelmetVerifyPush`)](#3314-fire--smoke--pyrotechnics-detection)
     - [Safe Riding / E-Bike Helmet Violations (`SafetyRidePush`)](#3315-safe-riding--e-bike-helmet-violations)
     - [Electric Spark & Extreme Temperature (`TemHighSnapPush`)](#3316-electric-spark--extreme-temperature)
   - [Manual Push & Resend Control Records (`ManualPushRecords` / `ManualPushSnaps`)](#34-manual-push--resend-control-records)
4. [Device Maintenance & System Operations](#4-device-maintenance--system-operations)
   - [MQTT Configuration (`GetMQTTParam` / `SetMQTTParam`)](#41-mqtt-configuration)
   - [System Parameters (`GetSysParam` / `SetSysParam`)](#42-system-parameters)
   - [Device Information (`GetDeviceInformation`)](#43-device-information)
   - [Handshake Data Storage (`GetHandSharkData` / `SetHandSharkData`)](#44-handshake-data-storage)
   - [Flow / Directional Counts (`GetCount`)](#45-flow--directional-counts)
   - [System Actions: Reboot, Factory Reset, Firmware Upgrade](#46-system-actions-reboot-factory-reset-firmware-upgrade)
5. [Complete Appendix & Error Code Reference](#5-complete-appendix--error-code-reference)

---

## 1. Architectural Overview & Communication Principles

### 1.1 Protocol Basics
- **Transport**: Standard HTTP / HTTPS over TCP.
- **HTTP Method**: All API control and subscription requests use **POST**.
- **Data Format**: `application/json` (UTF-8 encoded) for control APIs; `multipart/form-data` for video uploads and specific alarm streams.
- **Header Rules**: HTTP requests MUST contain valid `Content-Length` or `Transfer-Encoding: chunked` (case-sensitive).
- **Image Transmission**:
  - `picinfo`: Base64-encoded string (`data:image/jpeg;base64,...`). Max size usually 1MB (or 2MB for scene images).
  - `picURI`: Public or local HTTP/HTTPS URL accessible by the camera (camera downloads image directly).
  - *Note:* If both `picinfo` and `picURI` are supplied, `picinfo` takes priority.

### 1.2 Request Grammar & Addressing
Standard endpoint format:
```http
POST http://<camera_ip>:<port>/action/<Operator>
```
- Default Port: `8080` (or `80`)
- `<Operator>` corresponds exactly to the action requested in the JSON payload body.

### 1.3 Authentication
All camera management endpoints utilize standard **HTTP Basic Authentication**:
```http
Authorization: Basic <base64(username:password)>
```
*Example:* `admin:admin` &rarr; `Basic YWRtaW46YWRtaW4=`

### 1.4 Standard Request & Response Envelopes

#### Request Payload Envelope:
```json
{
  "operator": "<OperatorName>",
  "info": {
    "DeviceID": "<DeviceID>",
    "...": "..."
  }
}
```

#### Success Response Envelope:
```json
{
  "operator": "<OperatorName>",
  "code": 200,
  "info": {
    "Result": "Ok"
  }
}
```

#### Failure Response Envelope:
```json
{
  "operator": "<OperatorName>",
  "code": 461,
  "info": {
    "Result": "Fail",
    "Detail": "<Error message>"
  }
}
```

---

## 2. Personnel List Management (Face Database)

The camera supports local face matching with blacklist / whitelist capabilities.

### 2.1 Data Structures & Fields

| Field Name | Type | Options / Formats | Description |
| :--- | :--- | :--- | :--- |
| `DeviceID` | string / int | e.g. `"005a213b000b93cc"` | Device serial/ID |
| `IdType` | int | `0`: CustomizeID, `1`: LibID (deprecated), `2`: PersonUUID | ID scheme identifier |
| `CustomizeID`| uint / str | Numeric ID | User-defined integer identifier (Cannot be 0) |
| `PersonUUID` | string | Max 48 chars | User-defined unique string ID |
| `PersonType` | int | `0`: Whitelist / Allow, `1`: Blacklist / Block | Personnel category |
| `Name` | string | Max 32 chars | Person's display name |
| `Gender` | int | `0`: Male, `1`: Female, `2`: All (query only) | Gender |
| `IdCard` | string | Max 32 chars | Identification / National ID number |
| `Birthday` | string | `YYYY-MM-DD` | Birthdate |
| `Telnum` | string | Max 32 chars | Contact phone number |
| `Address` | string | Max 72 chars | Residential / Office address |
| `Native` | string | Province / Origin | Native place |
| `Notes` | string | Custom text | Remarks |
| `MjCardNo` | int / str | Card number | Wiegand / RFID / IC card number |
| `MjCardFrom` | int | Card type / format | Card format identifier |
| `tempValid` | int | `0`: Permanent, `1`: Temporary | Validity rule |
| `validBegin` | string | `YYYY-MM-DD hh:mm:ss` | Validity start timestamp |
| `validEnd` | string | `YYYY-MM-DD hh:mm:ss` | Validity expiration timestamp |
| `effectNumber`| int | `1`: Infinite, `1~10000`: Finite pass count | Remaining allowed admissions |
| `picinfo` | string | Base64 URI | Facial image data (`< 1MB`) |
| `picURI` | string | `http://...` / `https://...` | Facial image URL |

---

### 2.2 Add Multiple Persons (`AddPersons`)
- **URI**: `POST /action/AddPersons`
- **Capacity**: Up to 32 persons when sending base64 (`picinfo`), up to 1000 persons via URL (`picURI`).

```json
{
  "operator": "AddPersons",
  "DeviceID": "005a213b000b93cc",
  "Total": 1,
  "Personinfo_0": {
    "PersonType": 0,
    "Name": "John Doe",
    "Gender": 0,
    "IdCard": "430923199011044411",
    "Birthday": "1990-11-04",
    "Telnum": "18888888888",
    "Native": "Guangdong",
    "Address": "Shenzhen",
    "CustomizeID": 120,
    "tempValid": 1,
    "validBegin": "2023-11-10 00:00:00",
    "validEnd": "2024-12-10 00:00:00",
    "effectNumber": 100,
    "picURI": "https://example.com/images/johndoe.jpg"
  }
}
```

---

### 2.3 Batch Add/Modify Persons (`EditPersonsNew`)
- **URI**: `POST /action/EditPersonsNew`
- Supports batch inserts and updates via an `info` array.

```json
{
  "operator": "EditPersonsNew",
  "DeviceID": "005a213b000b93cc",
  "IdType": 0,
  "Total": 2,
  "info": [
    {
      "CustomizeID": 123,
      "PersonType": 0,
      "Name": "Alice",
      "Gender": 1,
      "IdCard": "430923199011044411",
      "Birthday": "1999-11-04",
      "Telnum": "19999999999",
      "Address": "Shenzhen",
      "tempValid": 0,
      "picURI": "https://example.com/alice.jpg"
    },
    {
      "CustomizeID": 456,
      "PersonType": 0,
      "Name": "Bob",
      "Gender": 0,
      "IdCard": "430923199011044412",
      "Birthday": "1999-11-05",
      "Telnum": "18888888888",
      "Address": "Ningde",
      "tempValid": 0,
      "picURI": "https://example.com/bob.jpg"
    }
  ]
}
```

---

### 2.4 Add or Modify Single Person (`EditPersonNew`)
- **URI**: `POST /action/EditPersonNew`
- Unmodified fields can be omitted. If `picinfo`/`picURI` is omitted, the existing image is preserved.

```json
{
  "operator": "EditPersonNew",
  "info": {
    "DeviceID": "005a213b000b93cc",
    "IdType": 0,
    "CustomizeID": 123,
    "PersonType": 0,
    "Name": "Alice Smith",
    "Telnum": "19999999999",
    "Address": "Shenzhen Bao'an",
    "tempValid": 0
  }
}
```

---

### 2.5 Delete Single/Multiple Persons (`DeletePerson`)
- **URI**: `POST /action/DeletePerson`
- Deletes specified IDs and their associated authentication logs.

```json
{
  "operator": "DeletePerson",
  "info": {
    "DeviceID": "005a213b000b93cc",
    "TotalNum": 2,
    "IdType": 0,
    "CustomizeID": [123, 456]
  }
}
```
*For UUID deletion, set `"IdType": 2` and supply `"PersonUUID": ["uuid1", "uuid2"]`.*

---

### 2.6 Delete All Persons (`DeleteAllPerson`)
- **URI**: `POST /action/DeleteAllPerson`
- **Warning**: Deletes the entire face database and all logs. **Camera automatically reboots upon completion.**

```json
{
  "operator": "DeleteAllPerson",
  "info": {
    "DeleteAllPersonCheck": 1
  }
}
```

---

### 2.7 Query Single Person (`SearchPerson`)
- **URI**: `POST /action/SearchPerson`

```json
{
  "operator": "SearchPerson",
  "info": {
    "DeviceID": "005a213b000b93cc",
    "SearchType": 0,
    "SearchID": "123",
    "Picture": 1
  }
}
```

---

### 2.8 Query Total Person Count (`SearchPersonNum`)
- **URI**: `POST /action/SearchPersonNum`

**Request:**
```json
{
  "operator": "SearchPersonNum",
  "info": {
    "DeviceID": "005a213b000b93cc"
  }
}
```
**Response:**
```json
{
  "operator": "SearchPersonNum",
  "code": 200,
  "info": {
    "DeviceID": "005a213b000b93cc",
    "MaxListsNum": 10000,
    "PersonNum": 150
  }
}
```

---

### 2.9 Query Multi-Person List / Search (`SearchPersonList`)
- **URI**: `POST /action/SearchPersonList`
- Supports filtering by Name, Date Range, Gender, Age, and pagination with `BeginNO` and `RequestCount` (Max `100` records per page).

```json
{
  "operator": "SearchPersonList",
  "info": {
    "DeviceID": "005a213b000b93cc",
    "PersonType": 2,
    "BeginTime": "2024-01-01T00:00:00",
    "EndTime": "2025-12-31T23:59:59",
    "Gender": 2,
    "Age": "0-100",
    "Name": "",
    "RequestCount": 20,
    "BeginNO": 0,
    "Picture": 0
  }
}
```

---

## 3. Event Subscriptions & Push Notifications (Webhooks)

The camera acts as an HTTP client and POSTs events to the designated server webhook URLs.

```
+-------------------+                      +-----------------------+
|  Camera Device    |                      |  Application Server   |
+-------------------+                      +-----------------------+
          |                                            |
          |  1. POST /action/Subscribe                 |
          |<-------------------------------------------| (Configure Webhooks)
          |  2. Response: 200 OK                       |
          |------------------------------------------->|
          |                                            |
          |  3. POST /Subscribe/heartbeat (Every 30s)  |
          |------------------------------------------->|
          |  4. {"code":200, "desc":"OK"}              |
          |<-------------------------------------------|
          |                                            |
          |  5. Event Triggered (Verify / Snap / Alarm)|
          |  POST /Subscribe/Verify or /Snap           |
          |------------------------------------------->|
          |  6. {"code":200, "desc":"OK"} (If Resume=1)|
          |<-------------------------------------------|
```

### 3.1 Subscription Management

#### Register / Update Subscription (`Subscribe`):
- **URI**: `POST /action/Subscribe`
- **Topics**: `"Snap"` (Stranger captures / alarms), `"VerifyWithSnap"` (Auth with face photo), `"VerifyNoPic"` (Auth without photo).
- **ResumefromBreakpoint**:
  - `0`: Fire-and-forget push.
  - `1`: Reliable push. The application server **MUST respond** with `{"code": 200, "desc": "OK"}` within 10 seconds, otherwise the camera re-pushes the same event.
- **Auth**: `"none"` or `"Basic"` (credentials camera sends when calling server webhook).

```json
{
  "operator": "Subscribe",
  "info": {
    "DeviceID": "005a213b000b93cc",
    "Num": 2,
    "Topics": ["Snap", "VerifyWithSnap"],
    "SubscribeAddr": "http://192.168.2.11:8080",
    "SubscribeUrl": {
      "Snap": "/Subscribe/Snap",
      "Verify": "/Subscribe/Verify",
      "HeartBeat": "/Subscribe/heartbeat"
    },
    "BeatInterval": 30,
    "ResumefromBreakpoint": 1,
    "Auth": "Basic",
    "User": "admin",
    "Pwd": "password123"
  }
}
```

#### Query Subscription (`GetSubscribe`):
- **URI**: `POST /action/GetSubscribe`

#### Cancel Subscription (`Unsubscribe`):
- **URI**: `POST /action/Unsubscribe`
```json
{
  "operator": "Unsubscribe",
  "info": {
    "DeviceID": "005a213b000b93cc",
    "Num": 2,
    "Topics": ["Snap", "VerifyWithSnap"]
  }
}
```

---

### 3.2 Subscription Heartbeat

- **Camera Path**: `POST /Subscribe/heartbeat` (Sent every 30 seconds)
- **Camera Payload**:
```json
{
  "operator": "HeartBeat",
  "info": {
    "DeviceID": "005a213b000b93cc",
    "Time": "2024-06-24T09:56:15"
  }
}
```
- **Server Response**:
```json
{
  "code": 200,
  "desc": "OK"
}
```

---

### 3.3 Push Events (Camera -> Server Webhooks)

#### 3.3.1 Stranger Capture Push (`SnapPush`)
Sent to `/Subscribe/Snap`:
```json
{
  "operator": "SnapPush",
  "info": {
    "DeviceID": "1299517",
    "CreateTime": "2024-05-28T19:00:38",
    "Sendintime": 1,
    "PushType": 2,
    "ManualPushID": 0,
    "nRecordIndex": 37
  },
  "SanpPic": "data:image/jpeg;base64,...",
  "ScenePic": "data:image/jpeg;base64,..."
}
```

#### 3.3.2 Verification / Recognition Result (`VerifyPush`)
Sent to `/Subscribe/Verify`:
```json
{
  "operator": "VerifyPush",
  "info": {
    "DeviceID": "123555",
    "PersonID": 105,
    "CustomizeID": "123",
    "PersonUUID": "",
    "Name": "John Doe",
    "PersonType": 0,
    "Similarity1": 92.4,
    "VerifyStatus": 1,
    "VerfyType": 1,
    "IdCard": "430923199011044411",
    "Telnum": "18888888888",
    "CreateTime": "2024-06-24T09:56:15",
    "Sendintime": 1,
    "PushType": 2,
    "nRecordIndex": 4
  },
  "SanpPic": "data:image/jpeg;base64,...",
  "ScenePic": "data:image/jpeg;base64,..."
}
```
*`VerifyStatus`: `0`: None, `1`: Allowed (Whitelisted), `2`: Rejected (Blacklisted / Denied), `3`: Unregistered.*  
*`VerfyType`: `1`: Whitelist face verification, `2`: ID card verification, `3`: Face + ID card.*

#### 3.3.3 Intelligent Violation Alarm
```json
{
  "operator": "SnapPush",
  "info": {
    "DeviceID": "005a213b000b93cc",
    "AlarmAction": "Not wearing a chef's hat",
    "CreateTime": "2024-05-28T17:18:40",
    "Pic": "data:image/jpeg;base64,..."
  }
}
```

#### 3.3.4 Objects Dropped from Height
- **Event JSON (`ParabolicSnapPush`)**:
```json
{
  "operator": "ParabolicSnapPush",
  "info": {
    "DeviceID": "005a213b000b93cc",
    "ID": 1005,
    "StartTime": "2024-05-28T17:18:38",
    "EndTime": "2024-05-28T17:18:42"
  },
  "Pic": "data:image/jpeg;base64,..."
}
```
- **Video Clip Push (`POST /Subscribe/PushVideo`)**: Multi-part form-data with `DeviceID`, `ID`, `StartTime`, `EndTime`, and `file` (MP4 format).

#### 3.3.5 Thermal Imaging & Spark Alarm (`TemHighSnapPush`)
```json
{
  "operator": "TemHighSnapPush",
  "info": {
    "DeviceID": "005a213b000b93cc",
    "SnapTime": "2024-02-14 11:01:02",
    "SparkAlarm": 0,
    "Message": [
      {
        "ArssssseaId": 0,
        "MessageType": 0,
        "AlarmType": 1,
        "TemRise": 0,
        "DurationTime": 30,
        "TemRiseDuration": 5,
        "Temperature": 45.5
      }
    ]
  },
  "Pic": "data:image/jpeg;base64,...",
  "TemPic": "data:image/jpeg;base64,..."
}
```

#### 3.3.6 Personnel & Vehicular Capture (`FullStrSnapPush`)
- `ObjectType`: `0`: Face, `1`: Human body, `2`: Motor vehicle, `3`: Non-motor vehicle.
```json
{
  "operator": "FullStrSnapPush",
  "info": {
    "DeviceID": "005a213b000b93cc",
    "snapTime": "2024-05-28T17:18:40",
    "ObjectType": 2,
    "ObjectAttr": {
      "Carplate": "粤B888H9"
    },
    "Pic": "data:image/jpeg;base64,..."
  }
}
```

#### 3.3.7 Behavioral Analysis (`BehaviorSnapPush`)
- `BehaviorType`: `0`: Tripwire incursion, `1`: Area incursion.
- `BehaviorDirection`: `0`: Left to right, `1`: Right to left, `2`: Enter area, `3`: Leave area.
```json
{
  "operator": "BehaviorSnapPush",
  "info": {
    "DeviceID": "005a213b000b93cc",
    "CreateTime": "2024-05-28T17:18:40",
    "objectType": 0,
    "BehaviorType": 0,
    "BehaviorDirection": 0,
    "Pic": "data:image/jpeg;base64,..."
  }
}
```

#### 3.3.8 Absence from Post / Work Monitoring (`LeaveSnapPush`)
```json
{
  "operator": "LeaveSnapPush",
  "info": {
    "DeviceID": "005a213b000b93cc",
    "CreateTime": "2024-05-28 17:18:40",
    "Pic": "data:image/jpeg;base64,..."
  }
}
```

#### 3.3.9 License Plate Recognition (`PlateSnapPush`)
```json
{
  "operator": "PlateSnapPush",
  "info": {
    "DeviceID": "1021544",
    "ID": 2,
    "AlarmAction": "Licence Plate Recognition",
    "PlateColour": 1,
    "PlateType": 1,
    "LicencePlate": "浙B00XS9",
    "RecordTime": 11
  },
  "PlatePic": "data:image/jpeg;base64,...",
  "ScenePic": "data:image/jpeg;base64,..."
}
```
*Plate Color*: `0`: Unknown, `1`: Blue, `2`: Yellow, `3`: White, `4`: Black, `5`: Green, `6`: Yellow-green, `7`: Gradient Green.

#### 3.3.10 Regional Headcount (`CountInRegionPush`)
```json
{
  "operator": "CountInRegionPush",
  "info": {
    "DeviceID": "005a213b000b93cc",
    "time": "2024-05-28T17:18:40",
    "realtimeCount": 5,
    "averageCount": 4,
    "maxCount": 8,
    "minCount": 1
  }
}
```

#### 3.3.11 Vehicle Attributes (`CatAttrSnapPush`)
Includes comprehensive attributes: `carColor` (0-13), `carClass` (Sedan, SUV, Truck, Bus, Minivan, Trailer, etc.), `carSpecial` (Police, Ambulance, Fire, Taxi, Express, etc.), `carBackup` (spare tire), `carViewpoint` (Front/Rear/Side), `carplateColor`, `carplateType`, `carplateFlag` (obstruction/missing plate detection).

#### 3.3.12 Vehicle Washing / Flushing Inspection
- Sent to `/Subscribe/PlateSnapPush` as `multipart/form-data`.
- Includes `EventType` (`0`: Washed/Flushing, `1`: Detour without washing), license plate info, image, and MP4 video clip.

#### 3.3.13 PPE Violation: Helmet & Reflective Vest (`ClothHelmetVerifyPush` / `ClothHelmetSnapPush`)
- `Helmet`: `0`: Wearing, `1`: Not wearing, `2`: Not sure.
- `ReflectiveVest`: `0`: Wearing, `1`: Not wearing, `2`: Not sure.

#### 3.3.14 Fire & Smoke / Pyrotechnics Detection (`FireSmokeSnapPush`)
- `Fire`: `0`: None, `1`: Present, `2`: Not sure.
- `Smoke`: `0`: None, `1`: Present, `2`: Not sure.

#### 3.3.15 Safe Riding / E-Bike Helmet Violations (`SafetyRidePush`)
- `helmet`: `0`: No helmet, `1`: Wearing helmet.
- `plate`: Electric bike license plate number.

#### 3.3.16 Electric Spark & Extreme Temperature (`TemHighSnapPush`)
- `sparkAlarm`: `0`: No, `1`: Yes, `2`: Spark only.

---

### 3.4 Manual Push & Resend Control Records

Used to retrieve or re-stream missing logs from camera storage to the server over a given timeframe.

- **Manual Push Identification Records (`ManualPushRecords`)**:
  - **URI**: `POST /action/ManualPushRecords`
  - **Payload**:
    ```json
    {
      "operator": "ManualPushRecords",
      "info": {
        "DeviceID": 123555,
        "TimeS": "2024-05-28T19:00:00",
        "TimeE": "2024-05-29T00:00:00"
      }
    }
    ```
- **Manual Push Stranger Records (`ManualPushSnaps`)**:
  - **URI**: `POST /action/ManualPushSnaps`
  - **Payload**:
    ```json
    {
      "operator": "ManualPushSnaps",
      "info": {
        "DeviceID": 123555,
        "TimeS": "2024-05-28T19:00:00",
        "TimeE": "2024-05-29T00:00:00"
      }
    }
    ```

---

## 4. Device Maintenance & System Operations

### 4.1 MQTT Configuration
- **Get MQTT Settings**: `POST /action/GetMQTTParam`
- **Set MQTT Settings**: `POST /action/SetMQTTParam`
```json
{
  "operator": "SetMQTTParam",
  "info": {
    "MQEnable": 1,
    "MQAddr": "192.168.2.100",
    "MQPort": 1883,
    "MQUser": "mqtt_user",
    "MQPwd": "mqtt_password",
    "MQTopic": "mqtt/face/1299517",
    "MQCloudID": "1299517",
    "StrangerUploadType": 0,
    "RecordUploadType": 1,
    "KeepAliveInterval": 60,
    "BasicTopic": "mqtt/face/basic",
    "HeartbeatTopic": "mqtt/face/heartbeat",
    "ResumefromBreakpoint": 1
  }
}
```

### 4.2 System Parameters
- **Get**: `POST /action/GetSysParam` &rarr; returns `Name`, `DeviceID`, `Version`, `DeviceType`.
- **Set Device Name**: `POST /action/SetSysParam` with `{"info": {"Name": "Entrance_Camera_01"}}`.

### 4.3 Device Information
- **Get**: `POST /action/GetDeviceInformation`
  - `DeviceType`: `0`: IPC (Network Camera), `1`: DVR, `2`: NVR, `3`: Panel Unit.

### 4.4 Handshake Data Storage
Allows third-party systems to store arbitrary custom token/metadata (up to 4KB) persistently on the camera.
- **Get**: `POST /action/GetHandSharkData`
- **Set**: `POST /action/SetHandSharkData` with `{"info": {"DeviceID": "123", "HandSharkInfo": "custom_data"}}`.

### 4.5 Flow / Directional Counts
- **Query Body / Vehicle Passage Statistics**: `POST /action/GetCount`
  - `ObjectType`: `0`: Human body, `1`: Vehicle.
  - `BehaviourDirection`: `0`: Bidirectional, `1`: Left to right, `2`: Right to left.

### 4.6 System Actions: Reboot, Factory Reset, Firmware Upgrade
- **Reboot Device**: `POST /action/RebootDevice` with `{"info": {"DeviceID": "...", "IsRebootDevice": 1}}`.
- **Factory Reset**: `POST /action/SetFactoryDefault` with `{"info": {"DefaltNetPar": 0, "DefaltPerson": 1}}`.
- **Remote Firmware Upgrade**: `POST /action/Upgrade`
  ```json
  {
    "operator": "Upgrade",
    "info": {
      "DeviceID": "005a213b000b93cc",
      "Name": "Firmware_v2.0",
      "upgradeType": 1,
      "Path": "https://example.com/firmware/update.udx"
    }
  }
  ```

---

## 5. Complete Appendix & Error Code Reference

### 5.1 General Return Codes
| Code | Text | Meaning |
| :--- | :--- | :--- |
| `200` | Successful operation | Request processed successfully |
| `201` | `is not json!` | Request payload is not valid JSON |
| `461` | `Unknow operator` | Unknown operator string in request |
| `462` | `Parameter error` | Missing `info` root object or essential parameter |
| `463` | `Unknow DeviceID` | Device ID does not match target device |
| `464` | `Invalid Parameters` | One or more parameters are out of bounds or invalid |

### 5.2 Personnel Management Error Codes
| Code | Error Description | Notes / Context |
| :--- | :--- | :--- |
| `411` | Failed to get keyword `EditPersonsNew` | Batch operation syntax error |
| `412` | Failed to get keyword `info` | Batch operation syntax error |
| `414` | Failed to get keyword `Total` | Missing `Total` in batch |
| `415` | `Total out of range` | Exceeds max allowed count (32 base64 / 1000 URI) |
| `416` | `json of data and Total is not equal` | Array length does not match `Total` |
| `417/418` | `IdType error` | Missing or invalid `IdType` |
| `464` | Missing image or `picinfo`/`picURI` error | Neither base64 nor URI provided |
| `465` | `URI image timeout or download image failure` | Camera failed to fetch URL (check camera DNS/network) |
| `466` | `CustomizeID or UUID had existed` / `URI image data < 1000 bytes` | Conflict or truncated image download |
| `467` | `Image data is too large` | Image exceeds 1MB |
| `468` | `Failed to extract facial features` | Image has no detectable face or bad angle |
| `469` | `Failure to write image data to database` | Database write failure on camera storage |
| `470` | `Failed to modify list in database` | Database update failure |
| `471` | `Person already exist` | Person record already registered |
| `473` | `Face Undetected` | No face found in provided image |
| `474` | `picinfo Base64 decode error` | Malformed base64 image data |
| `475` | `WG_CardNo already exist` | Wiegand / RFID card ID duplicate |
| `476` | `Get picinfo and connect URI IP error` | Network connection to image server failed |
| `477` | `Get URI pic data too short` | Downloaded image file incomplete |
| `482` | `InsertPerson2DBWithUUID err` | Database insertion failure with UUID |

### 5.3 Subscription & Query Error Codes
| Code | Error Description |
| :--- | :--- |
| `464` | `Unkonw Topic` / `Unkonw Picture` |
| `465` | `Unkonw Num` |
| `466` | `Unkonw Topics` / `People Statistics Not Enabled` |
| `467` | `Unkonw SearchID` / `Record not found in time range` |
| `468` | `Unkonw IdType` / `Last manual push not completed` |

### 5.4 Firmware Upgrade Error Codes
| Code | Description |
| :--- | :--- |
| `464` | `Unkonw Path` - Missing upgrade file URL |
| `466` | `Can't find upgrade file` |
| `468` | `Unkonw Name` |
| `475` | `upgradeType Not supported` |
| `483` | `download file fail` |
| `484` | `file too big` |
| `485` | `get file length is error` |
| `486` | `upgrade fail` |
