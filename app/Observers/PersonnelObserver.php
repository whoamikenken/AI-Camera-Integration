<?php

namespace App\Observers;

use App\Jobs\SyncPersonnelJob;
use App\Models\Personnel;

class PersonnelObserver
{
    public function created(Personnel $personnel): void
    {
        SyncPersonnelJob::dispatch($personnel->id, 'ADD');
    }

    public function updated(Personnel $personnel): void
    {
        SyncPersonnelJob::dispatch($personnel->id, 'EDIT');
    }

    public function deleting(Personnel $personnel): void
    {
        SyncPersonnelJob::dispatch($personnel->id, 'DELETE', null, $personnel->customize_id);
    }
}
