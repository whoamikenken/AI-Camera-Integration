<?php

namespace App\Gateways;

use App\Contracts\CameraGatewayInterface;
use Illuminate\Support\Facades\Facade;

/**
 * @method static FakeCameraGateway fake(array $responses = [])
 * @method static void assertDispatched(string $operator, ?callable $callback = null)
 * @method static void assertDispatchedTimes(string $operator, int $times = 1)
 * @method static void assertNotDispatched(string $operator, ?callable $callback = null)
 * @method static void assertNothingDispatched()
 * @method static \Illuminate\Support\Collection dispatched(?string $operator = null, ?callable $callback = null)
 * @method static array publishCommand(\App\Models\Device $device, string $operator, array $info = [], array $extraRootFields = [])
 * @method static array publishCommandAndWait(\App\Models\Device $device, string $operator, array $info = [], array $extraRootFields = [], float $timeoutSeconds = 1.8)
 * @method static array testConnection(\App\Models\Device $device, array $overrides = [])
 * @method static array rebootDevice(\App\Models\Device $device)
 * @method static array configureMqtt(\App\Models\Device $device, array $params = [])
 * @method static array getMqttParam(\App\Models\Device $device)
 * @method static array getSysParam(\App\Models\Device $device)
 * @method static array setSysParam(\App\Models\Device $device, array $params = [])
 * @method static array setSysTime(\App\Models\Device $device, ?string $time = null)
 * @method static array getDeviceInformation(\App\Models\Device $device)
 * @method static array getSceneSnap(\App\Models\Device $device)
 * @method static array searchPerson(\App\Models\Device $device, string $searchId, int $searchType = 0, int $picture = 0)
 * @method static array searchPersonList(\App\Models\Device $device, int $beginNo = 0, int $count = 50)
 * @method static array searchPersonNum(\App\Models\Device $device)
 * @method static array addOrUpdatePerson(\App\Models\Device $device, \App\Models\Personnel $person)
 * @method static array deletePerson(\App\Models\Device $device, array $customizeIds)
 * @method static array deleteAllPersonnel(\App\Models\Device $device)
 * @method static array subscribe(\App\Models\Device $device, array $topics = ['Snap', 'VerifyWithSnap'], ?string $subscribeAddr = null, ?array $urls = null, int $beatInterval = 30, int $resumeFromBreakpoint = 1)
 * @method static array unsubscribe(\App\Models\Device $device, array $topics = ['Snap', 'VerifyWithSnap'])
 * @method static mixed dispatchCommandAsync(\App\Models\Device $device, string $operator, array $params = [])
 *
 * @see \App\Contracts\CameraGatewayInterface
 * @see \App\Gateways\FakeCameraGateway
 */
class CameraGateway extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CameraGatewayInterface::class;
    }

    /**
     * Replace the bound instance with a fake for testing.
     */
    public static function fake(array $responses = []): FakeCameraGateway
    {
        $fake = new FakeCameraGateway($responses);
        static::swap($fake);
        app()->instance(CameraGatewayInterface::class, $fake);

        return $fake;
    }
}
