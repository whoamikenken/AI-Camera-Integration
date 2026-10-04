<?php

namespace App\Observers;

use App\Events\SyncTaskUpdated;
use App\Models\SyncTask;

class SyncTaskObserver
{
    public function created(SyncTask $syncTask): void
    {
        broadcast(new SyncTaskUpdated($syncTask, null));
    }

    public function updated(SyncTask $syncTask): void
    {
        if ($syncTask->isDirty('status')) {
            $oldStatus = $syncTask->getOriginal('status');
            broadcast(new SyncTaskUpdated($syncTask, $oldStatus));
        }
    }
}
