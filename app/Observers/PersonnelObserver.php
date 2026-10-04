<?php

namespace App\Observers;

use App\Events\PersonnelUpdated;
use App\Jobs\SyncPersonnelJob;
use App\Models\Personnel;

class PersonnelObserver
{
    public function created(Personnel $personnel): void
    {
        SyncPersonnelJob::dispatch($personnel->id, 'ADD');
        broadcast(new PersonnelUpdated(
            action: 'created',
            personType: (int) $personnel->person_type,
            oldPersonType: null,
            personnelId: $personnel->id,
            name: $personnel->name
        ));
    }

    public function updated(Personnel $personnel): void
    {
        SyncPersonnelJob::dispatch($personnel->id, 'EDIT');
        broadcast(new PersonnelUpdated(
            action: 'updated',
            personType: (int) $personnel->person_type,
            oldPersonType: $personnel->isDirty('person_type') ? (int) $personnel->getOriginal('person_type') : null,
            personnelId: $personnel->id,
            name: $personnel->name
        ));
    }

    public function deleting(Personnel $personnel): void
    {
        // Telemetry access logs are preserved as immutable compliance audit records

        \App\Models\SyncTask::where('personnel_id', $personnel->id)->delete();

        SyncPersonnelJob::dispatch($personnel->id, 'DELETE', null, $personnel->customize_id);
        broadcast(new PersonnelUpdated(
            action: 'deleted',
            personType: (int) $personnel->person_type,
            oldPersonType: null,
            personnelId: $personnel->id,
            name: $personnel->name
        ));
    }
}
