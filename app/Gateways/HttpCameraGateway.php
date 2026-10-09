<?php

namespace App\Gateways;

use App\Contracts\CameraGatewayInterface;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Personnel;
use App\Services\CameraHttpService;

class HttpCameraGateway extends CameraHttpService implements CameraGatewayInterface
{
    public function publishCommand(Device $device, string $operator, array $info = [], array $extraRootFields = []): array
    {
        return $this->postAction($device, $operator, $info, $extraRootFields);
    }

    public function publishCommandAndWait(Device $device, string $operator, array $info = [], array $extraRootFields = [], float $timeoutSeconds = 1.8): array
    {
        return $this->postAction($device, $operator, $info, $extraRootFields, (int) ceil($timeoutSeconds));
    }

    public function setSysParam(Device $device, array $params = []): array
    {
        return $this->postAction($device, 'SetSysParam', $params);
    }

    public function getSceneSnap(Device $device): array
    {
        return $this->postAction($device, 'GetSceneSnap', [
            'ImgType' => 2,
            'ImgQuality' => 80,
        ]);
    }

    public function addPersons(Device $device, array $personnelItems): array
    {
        return parent::addPersons($device, $personnelItems);
    }

    public function dispatchCommandAsync(Device $device, string $operator, array $params = []): DeviceCommand
    {
        $messageId = 'CMD-' . strtoupper(substr(uniqid(), -8));

        $command = DeviceCommand::create([
            'device_id' => $device->id,
            'message_id' => $messageId,
            'operator' => $operator,
            'status' => 'pending',
            'payload' => array_merge(['facesluiceId' => $device->device_id], $params),
            'dispatched_at' => now(),
        ]);

        $this->publishCommand($device, $operator, $params, ['messageId' => $messageId]);

        return $command;
    }
}
