<?php

namespace Database\Factories;

use App\Models\BulkCampaign;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BulkCampaign>
 */
class BulkCampaignFactory extends Factory
{
    protected $model = BulkCampaign::class;

    public function definition(): array
    {
        return [
            'user_id' => null,
            'campaign_type' => fake()->randomElement(['reboot_fleet', 'update_mqtt_config', 'sync_personnel', 'delete_personnel']),
            'total_items' => fake()->numberBetween(1, 50),
            'processed_items' => 0,
            'failed_items' => 0,
            'status' => 'pending',
            'payload' => [],
            'error_summary' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => 'pending',
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn () => [
            'status' => 'processing',
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'processed_items' => $attributes['total_items'] ?? 10,
            'failed_items' => 0,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'failed_items' => $attributes['total_items'] ?? 10,
            'error_summary' => 'Operation timed out on remote devices',
        ]);
    }

    public function rebootFleet(): static
    {
        return $this->state(fn () => [
            'campaign_type' => 'reboot_fleet',
            'payload' => ['device_ids' => [1, 2, 3]],
        ]);
    }

    public function syncPersonnel(): static
    {
        return $this->state(fn () => [
            'campaign_type' => 'sync_personnel',
            'payload' => ['personnel_ids' => [1, 2, 3]],
        ]);
    }

    public function updateMqttConfig(): static
    {
        return $this->state(fn () => [
            'campaign_type' => 'update_mqtt_config',
            'payload' => [
                'device_ids' => [1, 2],
                'mqtt_config' => ['KeepAlive' => 60, 'RecordUploadType' => 1],
            ],
        ]);
    }

    public function deletePersonnel(): static
    {
        return $this->state(fn () => [
            'campaign_type' => 'delete_personnel',
            'payload' => ['personnel_ids' => [1, 2]],
        ]);
    }

    public function withUser(?User $user = null): static
    {
        return $this->state(fn () => [
            'user_id' => $user?->id ?? User::factory(),
        ]);
    }
}
