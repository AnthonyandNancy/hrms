<?php

declare(strict_types=1);

namespace App\Modules\IAM\Auth;

use App\Modules\IAM\Models\Session;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Services\SessionManager;
use App\Shared\Authorization\Contracts\PersonEmploymentDirectory;
use Illuminate\Http\Request;

/**
 * Resolves the session cookie into a Principal and applies every per-request
 * hard-deny check: token hash, revocation, idle/absolute expiry, user status,
 * tenant binding and auth_version.
 */
final class PrincipalResolver
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly PersonEmploymentDirectory $people,
    ) {}

    /**
     * @return array{0: AuthenticatedPrincipal, 1: Session, 2: User}
     */
    public function resolve(Request $request): array
    {
        $token = $request->cookie((string) config('iam.session.cookie', 'hrms_session')) ?? $request->cookies->get((string) config('iam.session.cookie', 'hrms_session'));

        if (! is_string($token) || $token === '') {
            throw new AuthenticationFailedException;
        }

        $session = $this->sessions->findValid($token);
        $user = User::query()->whereKey($session->user_id)->first();

        if (! $user instanceof User || (int) $user->tenant_id !== (int) $session->tenant_id) {
            $this->sessions->revoke($session);

            throw new AuthenticationFailedException('SESSION_INVALID');
        }

        $this->sessions->assertAuthVersion($user, $session);
        $this->sessions->touch($session);

        $employmentIds = $this->people->employmentIdsForUser((int) $user->tenant_id, (int) $user->id);
        $employmentId = $employmentIds === [] ? null : (int) $employmentIds[0];

        $grantId = null;
        $rawGrant = $request->headers->get('X-Break-Glass-Grant');
        if (is_string($rawGrant) && ctype_digit($rawGrant)) {
            $grantId = (int) $rawGrant;
        }

        $principal = AuthenticatedPrincipal::fromSession($user, $session, $employmentId, $grantId);

        return [$principal, $session, $user];
    }
}
