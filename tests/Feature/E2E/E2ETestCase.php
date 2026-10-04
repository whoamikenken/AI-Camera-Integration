<?php

namespace Tests\Feature\E2E;

use App\Models\Device;
use App\Models\Personnel;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

abstract class E2ETestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * Require a specific database table to exist for progressive testability.
     * Skips the test if the table has not yet been introduced by an upcoming milestone.
     */
    protected function requireTable(string $table, string $milestone): void
    {
        if (!Schema::hasTable($table)) {
            $this->markTestSkipped("Awaiting {$milestone}: Database table '{$table}' is not yet migrated.");
        }
    }

    /**
     * Require a specific route URI and method to be registered.
     */
    protected function requireRoute(string $uri, string $method = 'GET', string $milestone = 'Milestone'): void
    {
        $routes = Route::getRoutes();
        $matched = false;
        $cleanUri = ltrim($uri, '/');
        foreach ($routes as $route) {
            if (in_array(strtoupper($method), $route->methods())) {
                $pattern = '#^' . preg_replace('/\{[^}]+\}/', '[^/]+', $route->uri()) . '$#';
                if ($route->uri() === $cleanUri || preg_match($pattern, $cleanUri)) {
                    $matched = true;
                    break;
                }
            }
        }

        if (!$matched) {
            $this->markTestSkipped("Awaiting {$milestone}: Route [{$method} {$uri}] is not yet registered.");
        }
    }

    /**
     * Require a class to exist.
     */
    protected function requireClass(string $className, string $milestone): void
    {
        if (!class_exists($className)) {
            $this->markTestSkipped("Awaiting {$milestone}: Class '{$className}' does not exist yet.");
        }
    }

    /**
     * Helper to mock successful edge camera hardware HTTP POST responses.
     */
    protected function mockCameraSuccess(): void
    {
        Http::fake([
            '*/action/*' => Http::response([
                'code' => 0,
                'message' => 'Success',
                'data' => [
                    'Result' => 0,
                    'PersonID' => 101,
                    'CustomizeID' => 1001,
                ],
            ], 200),
        ]);
    }

    /**
     * Helper to mock edge camera hardware errors.
     */
    protected function mockCameraError(int $statusCode = 500, int $errorCode = 1): void
    {
        Http::fake([
            '*/action/*' => Http::response([
                'code' => $errorCode,
                'message' => 'Camera Hardware Failure',
                'data' => [],
            ], $statusCode),
        ]);
    }

    /**
     * Helper to create a standard test edge camera device.
     */
    protected function createTestDevice(array $overrides = []): Device
    {
        return Device::create(array_merge([
            'device_id' => 'CAM-E2E-' . uniqid(),
            'name' => 'Main Gate Entrance Camera',
            'ip_address' => '192.168.1.100',
            'port' => 8080,
            'scheme' => 'http',
            'username' => 'admin',
            'password' => 'admin',
            'device_type' => 0,
            'is_active' => true,
        ], $overrides));
    }

    /**
     * Helper to create a standard test personnel record (biometric face entity).
     */
    protected function createTestPersonnel(array $overrides = []): Personnel
    {
        return Personnel::create(array_merge([
            'customize_id' => rand(10000, 99999),
            'name' => 'Jane Doe',
            'person_type' => 0, // Whitelist
            'gender' => 1,
            'temp_valid' => 0, // Permanent
            'effect_number' => -1,
        ], $overrides));
    }

    /**
     * Helper to authenticate as an administrative user.
     */
    protected function actingAsAdmin(): User
    {
        $role = \App\Models\Role::firstOrCreate(['slug' => 'super-admin'], [
            'name' => 'Super Administrator',
            'is_system' => true,
        ]);

        $user = User::factory()->create([
            'name' => 'Super Administrator',
            'email' => 'admin-' . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        \Laravel\Sanctum\Sanctum::actingAs($user, ['*']);
        return $user;
    }
}
