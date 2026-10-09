<?php

namespace App\Contracts;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Personnel;

interface CameraGatewayInterface
{
    /**
     * Publish a downlink command to a device.
     */
    public function publishCommand(Device $device, string $operator, array $info = [], array $extraRootFields = []): array;

    /**
     * Publish a downlink command and wait for acknowledgment from the device.
     */
    public function publishCommandAndWait(Device $device, string $operator, array $info = [], array $extraRootFields = [], float $timeoutSeconds = 1.8): array;

    /**
     * Test connection reachability of the device.
     */
    public function testConnection(Device $device, array $overrides = []): array;

    /**
     * Reboot the device remotely.
     */
    public function rebootDevice(Device $device): array;

    /**
     * Configure MQTT parameters on the device.
     */
    public function configureMqtt(Device $device, array $params = []): array;

    /**
     * Retrieve current MQTT parameters from the device.
     */
    public function getMqttParam(Device $device): array;

    /**
     * Retrieve system parameters from the device.
     */
    public function getSysParam(Device $device): array;

    /**
     * Update system parameters on the device.
     */
    public function setSysParam(Device $device, array $params = []): array;

    /**
     * Synchronize device system clock.
     */
    public function setSysTime(Device $device, ?string $time = null): array;

    /**
     * Query hardware and firmware information from the device.
     */
    public function getDeviceInformation(Device $device): array;

    /**
     * Request an on-demand snapshot frame from the camera.
     */
    public function getSceneSnap(Device $device): array;

    /**
     * Search a single person detail by custom ID.
     */
    public function searchPerson(Device $device, string $searchId, int $searchType = 0, int $picture = 0): array;

    /**
     * Search enrolled person list on the device with pagination.
     */
    public function searchPersonList(Device $device, int $beginNo = 0, int $count = 50): array;

    /**
     * Search total count of enrolled persons on the device.
     */
    public function searchPersonNum(Device $device): array;

    /**
     * Add or update a person in the device face library.
     */
    public function addOrUpdatePerson(Device $device, Personnel $person): array;

    /**
     * Batch add personnel records to the device face library.
     */
    public function addPersons(Device $device, array $personnelItems): array;

    /**
     * Delete one or more person records by custom ID.
     */
    public function deletePerson(Device $device, array $customizeIds): array;

    /**
     * Delete all personnel from the device face library.
     */
    public function deleteAllPersonnel(Device $device): array;

    /**
     * Subscribe to event streams on the camera device.
     */
    public function subscribe(Device $device, array $topics = ['Snap', 'VerifyWithSnap'], ?string $subscribeAddr = null, ?array $urls = null, int $beatInterval = 30, int $resumeFromBreakpoint = 1): array;

    /**
     * Unsubscribe from event streams on the camera device.
     */
    public function unsubscribe(Device $device, array $topics = ['Snap', 'VerifyWithSnap']): array;

    /**
     * Dispatch an asynchronous downlink command.
     */
    public function dispatchCommandAsync(Device $device, string $operator, array $params = []): DeviceCommand;
}
