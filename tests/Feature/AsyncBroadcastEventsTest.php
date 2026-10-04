<?php

namespace Tests\Feature;

use App\Events\PersonnelUpdated;
use App\Events\SyncTaskUpdated;
use App\Events\VisitorCheckedIn;
use App\Events\VisitorCheckedOut;
use App\Models\SyncTask;
use App\Models\Visit;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AsyncBroadcastEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_task_updated_is_async_broadcast()
    {
        $this->assertAsyncBroadcast(SyncTaskUpdated::class);

        Queue::fake();

        $syncTask = new SyncTask();
        $syncTask->id = 1;
        event(new SyncTaskUpdated($syncTask));

        Queue::assertPushedOn('broadcasts', BroadcastEvent::class);
    }

    public function test_visitor_checked_in_is_async_broadcast()
    {
        $this->assertAsyncBroadcast(VisitorCheckedIn::class);

        Queue::fake();

        $visit = new Visit();
        $visit->id = 1;
        event(new VisitorCheckedIn($visit));

        Queue::assertPushedOn('broadcasts', BroadcastEvent::class);
    }

    public function test_visitor_checked_out_is_async_broadcast()
    {
        $this->assertAsyncBroadcast(VisitorCheckedOut::class);

        Queue::fake();

        $visit = new Visit();
        $visit->id = 1;
        event(new VisitorCheckedOut($visit));

        Queue::assertPushedOn('broadcasts', BroadcastEvent::class);
    }

    public function test_personnel_updated_is_async_broadcast()
    {
        $this->assertAsyncBroadcast(PersonnelUpdated::class);

        Queue::fake();

        event(new PersonnelUpdated('created', 0));

        Queue::assertPushedOn('broadcasts', BroadcastEvent::class);
    }

    private function assertAsyncBroadcast(string $eventClass)
    {
        $reflection = new \ReflectionClass($eventClass);

        $this->assertTrue(
            $reflection->implementsInterface(ShouldBroadcast::class),
            "{$eventClass} should implement ShouldBroadcast"
        );

        $this->assertFalse(
            $reflection->implementsInterface(ShouldBroadcastNow::class),
            "{$eventClass} should NOT implement ShouldBroadcastNow"
        );

        $defaultProperties = $reflection->getDefaultProperties();
        $this->assertArrayHasKey('broadcastQueue', $defaultProperties, "{$eventClass} must declare \$broadcastQueue");
        $this->assertSame('broadcasts', $defaultProperties['broadcastQueue'], "{$eventClass} \$broadcastQueue must be 'broadcasts'");
    }
}
