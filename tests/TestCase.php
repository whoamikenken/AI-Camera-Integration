<?php

namespace Tests;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    protected ?User $authenticatedUser = null;

    /**
     * Authenticate a test user with a specific role (default: super-admin).
     */
    protected function authenticateTestUser(string $roleSlug = 'super-admin'): User
    {
        $role = Role::firstOrCreate(['slug' => $roleSlug], [
            'name' => ucwords(str_replace('-', ' ', $roleSlug)),
            'is_system' => true,
        ]);

        $user = User::factory()->create();
        $user->roles()->syncWithoutDetaching([$role->id]);

        Sanctum::actingAs($user, ['*']);
        $this->authenticatedUser = $user;

        return $user;
    }

    /**
     * Auto-authenticate for tests unless specifically disabled.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! property_exists($this, 'disableAutoAuth') || ! $this->disableAutoAuth) {
            if (Schema::hasTable('users') && Schema::hasTable('roles')) {
                $this->authenticateTestUser('super-admin');
            }
        }
    }
}
