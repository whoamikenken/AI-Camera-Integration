<?php

namespace App\Services;

use App\Jobs\SyncPersonnelJob;
use App\Models\Personnel;
use App\Models\Visit;
use App\Models\Visitor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class VisitorSyncService
{
    /**
     * Provision temporary face biometric profile to camera network for checked-in visitor.
     */
    public function provisionVisitorFace(Visit $visit): ?Personnel
    {
        $visitor = $visit->visitor;
        if ($visitor && $visitor->is_blocked) {
            throw new HttpException(403, 'Visitor is on the security watchlist and cannot be provisioned.');
        }

        return DB::transaction(function () use ($visit, $visitor) {
            $customizeId = 900000 + $visit->id;

            $personnel = Personnel::create([
                'customize_id' => $customizeId,
                'name' => $visitor ? $visitor->name : "Visitor #{$visit->id}",
                'person_type' => 0, // Whitelist
                'temp_valid' => 1,
                'valid_begin' => now(),
                'valid_end' => now()->endOfDay(),
                'photo_path' => $visitor?->photo_path,
            ]);

            $visit->update(['personnel_id' => $personnel->id]);

            // Dispatch sync job to push face to entry turnstiles and cameras
            SyncPersonnelJob::dispatch($personnel, 'ADD');

            return $personnel;
        });
    }

    /**
     * Revoke and delete temporary face profile from camera network on checkout.
     */
    public function revokeVisitorFace(Visit $visit): void
    {
        $customizeId = 900000 + $visit->id;
        if ($visit->personnel_id) {
            $personnel = Personnel::find($visit->personnel_id);
            if ($personnel) {
                SyncPersonnelJob::dispatch($personnel->id, 'DELETE', null, $personnel->customize_id);
                $personnel->delete();
            } else {
                SyncPersonnelJob::dispatch($visit->personnel_id, 'DELETE', null, $customizeId);
            }

            $visit->update(['personnel_id' => null]);
        } else {
            $personnel = Personnel::where('customize_id', $customizeId)->first();
            if ($personnel) {
                SyncPersonnelJob::dispatch($personnel->id, 'DELETE', null, $personnel->customize_id);
                $personnel->delete();
            } else {
                SyncPersonnelJob::dispatch(null, 'DELETE', null, $customizeId);
            }
        }
    }

    /**
     * Cancel an expected or checked-in visit and revoke edge camera access.
     */
    public function cancelVisit(Visit $visit, ?\App\Models\User $user = null, ?string $reason = null): Visit
    {
        if (in_array($visit->status, ['cancelled', 'checked_out', 'no_show'])) {
            throw ValidationException::withMessages([
                'status' => ["Cannot cancel visit with status '{$visit->status}'."],
            ]);
        }

        // Immediately de-provision camera face whitelist
        $this->revokeVisitorFace($visit);

        $cancellationReason = !empty(trim((string) ($reason ?? ''))) ? trim((string) $reason) : 'Cancelled by user';
        $visit->update([
            'status' => 'cancelled',
            'cancellation_reason' => $cancellationReason,
            'cancelled_by' => $user?->id,
            'cancelled_at' => now(),
        ]);

        $freshVisit = $visit->fresh(['visitor', 'host', 'personnel']);

        try {
            \App\Events\VisitorCheckedOut::dispatch($freshVisit);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Failed to broadcast visitor cancellation: " . $e->getMessage());
        }

        return $freshVisit;
    }

    /**
     * Check in an expected or walk-in visitor.
     */
    public function checkIn(Visit $visit, array $data = []): Visit
    {
        if ($visit->status === 'checked_in') {
            throw ValidationException::withMessages([
                'status' => ['Visit is already checked in.'],
            ]);
        }

        $visitor = $visit->visitor;
        if ($visitor && $visitor->is_blocked) {
            throw new HttpException(403, 'Visitor is on the security watchlist and denied entry.');
        }

        $visit->update([
            'status' => 'checked_in',
            'check_in_time' => now(),
            'badge_number' => $data['badge_number'] ?? $visit->badge_number,
            'nda_signed' => isset($data['nda_signed']) ? (bool) $data['nda_signed'] : (bool) ($visit->nda_signed ?? false),
        ]);

        $this->provisionVisitorFace($visit);

        $freshVisit = $visit->fresh(['visitor', 'host', 'personnel']);
        try {
            \App\Events\VisitorCheckedIn::dispatch($freshVisit);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Failed to broadcast VisitorCheckedIn: " . $e->getMessage());
        }

        return $freshVisit;
    }

    /**
     * Check out a visitor and revoke camera whitelist access.
     */
    public function checkOut(Visit $visit): Visit
    {
        if ($visit->status === 'checked_out') {
            throw ValidationException::withMessages([
                'status' => ['Visit is already checked out.'],
            ]);
        }

        $visit->update([
            'status' => 'checked_out',
            'check_out_time' => now(),
        ]);

        $this->revokeVisitorFace($visit);

        $freshVisit = $visit->fresh(['visitor', 'host']);
        try {
            \App\Events\VisitorCheckedOut::dispatch($freshVisit);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Failed to broadcast VisitorCheckedOut: " . $e->getMessage());
        }

        return $freshVisit;
    }
}
