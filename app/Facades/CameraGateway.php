<?php

namespace App\Facades;

use App\Gateways\CameraGateway as BaseCameraGateway;

/**
 * Facade alias for CameraGateway.
 *
 * @see \App\Gateways\CameraGateway
 * @see \App\Gateways\FakeCameraGateway
 */
class CameraGateway extends BaseCameraGateway
{
}
