<?php

namespace App\Services;

use App\Models\RegularizationRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegularizationService
{
    /**
     * Cancel an unapproved regularization request.
     */
    public function cancelRegularization(
        RegularizationRequest $request,
        ?User $user = null,
        ?string $reason = null
    ): RegularizationRequest {
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ["Cannot cancel regularization request with status '{$request->status}'."],
            ]);
        }

        return DB::transaction(function () use ($request, $user, $reason) {
            $cancellationReason = !empty(trim((string) ($reason ?? ''))) ? trim((string) $reason) : 'Cancelled by user';
            $request->update([
                'status' => 'cancelled',
                'cancellation_reason' => $cancellationReason,
                'cancelled_by' => $user?->id,
                'cancelled_at' => now(),
            ]);

            return $request->fresh();
        });
    }
}
