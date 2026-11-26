<?php

declare(strict_types=1);

namespace App\Modules\IAM\Authorization;

use App\Modules\IAM\Models\BreakGlassGrant;
use App\Shared\Authorization\Contracts\ElevationGrantDirectory;

final class EloquentElevationGrantDirectory implements ElevationGrantDirectory
{
    /**
     * @return list<string>
     */
    public function activeCapabilities(int $userId, int $grantId): array
    {
        $grant = BreakGlassGrant::query()
            ->whereKey($grantId)
            ->where(function ($query) use ($userId): void {
                $query->where('requester_user_id', $userId)
                    ->orWhere('approver_user_id', $userId);
            })
            ->whereNull('revoked_at')
            ->where('expires_at', '>', app(\App\Shared\Time\Clock::class)->now())
            ->first();

        if ($grant === null) {
            return [];
        }

        /** @var list<string>|null $scope */
        $scope = $grant->scope_json;

        return $scope ?? ['*'];
    }
}
