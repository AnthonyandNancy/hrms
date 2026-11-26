<?php

declare(strict_types=1);

namespace App\Modules\IAM\Http\Controllers;

use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Services\RoleAdminService;
use App\Modules\IAM\Services\RolePageService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandResult;
use App\Shared\Commands\CommandRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Atomic role authorization save (ADR-0035 / ADR-0036).
 *
 * The workbench edits pages, action capabilities, data scopes and field
 * policies in one operator gesture. Saving them through four independent
 * endpoints could leave a role half-granted if one call failed, so the
 * workbench uses this single transactional endpoint instead. View access and
 * action access stay separate: a page code grants the view, its action
 * capabilities are granted explicitly.
 */
final class RoleAuthorizationController
{
    public function __construct(
        private readonly AuthenticationContext $context,
        private readonly RoleAdminService $roles,
        private readonly RolePageService $pages,
        private readonly CommandRunner $commands,
    ) {}

    public function update(Request $request, string $id): JsonResponse
    {
        $principal = $this->context->requirePrincipal();

        $data = $request->validate([
            'pages' => ['required', 'array', 'max:160'],
            'pages.*' => ['required', 'string', 'max:120'],
            'capabilities' => ['present', 'array', 'max:64'],
            'capabilities.*.capability' => ['required', 'string', 'max:120'],
            'capabilities.*.anchor_type' => ['required', 'string', 'max:24'],
            'capabilities.*.field_profile' => ['required', 'string', 'max:24'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $pageCodes = array_values(array_unique(array_map(
            static fn (mixed $code): string => (string) $code,
            (array) $data['pages'],
        )));
        $capabilities = array_values((array) $data['capabilities']);

        // Actions require their page. Reject the whole save instead of silently
        // dropping the action, so the operator sees the inconsistency.
        $missingPages = $this->missingActionPages($capabilities, $pageCodes);
        if ($missingPages !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'capabilities' => [
                    'Action capabilities require their page to be granted: '.implode(', ', $missingPages),
                ],
            ]);
        }

        $payload = [
            'role_code' => $id,
            'pages' => $pageCodes,
            'capabilities' => $capabilities,
            'reason' => $data['reason'] ?? null,
        ];

        $result = $this->commands->run(
            definition: new CommandDefinition(
                name: 'iam.role.authorization.replace',
                capability: 'iam.role.manage',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'iam.role.authorization.changed',
            ),
            payload: $payload,
            operation: function () use ($id, $pageCodes, $capabilities): array {
                return DB::transaction(function () use ($id, $pageCodes, $capabilities): array {
                    // Both services bump auth_version for every affected user
                    // inside this transaction; a failure here rolls back both.
                    $role = $this->roles->replaceCapabilities($id, $capabilities);
                    $pages = $this->pages->replace($id, $pageCodes);

                    return [
                        'http_status' => 200,
                        'body' => [
                            'role' => $role,
                            'pages' => $pages['page_codes'] ?? $pageCodes,
                        ],
                        'resource_type' => 'role',
                        'resource_id' => (string) ($role['code'] ?? $id),
                    ];
                });
            },
            idempotencyKey: (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result);
    }

    /**
     * @param  list<array<string, mixed>>  $capabilities
     * @param  list<string>  $pageCodes
     * @return list<string>
     */
    private function missingActionPages(array $capabilities, array $pageCodes): array
    {
        $known = array_fill_keys($pageCodes, true);
        $missing = [];

        foreach ($capabilities as $capability) {
            if (! is_array($capability)) {
                continue;
            }

            $page = isset($capability['page_code']) ? (string) $capability['page_code'] : '';
            if ($page !== '' && ! isset($known[$page])) {
                $missing[$page] = true;
            }
        }

        return array_keys($missing);
    }

    private function respond(CommandResult $result): JsonResponse
    {
        return ApiResponse::ok($result->body);
    }
}
