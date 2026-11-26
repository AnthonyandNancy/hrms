<?php

declare(strict_types=1);

namespace App\Modules\IAM\Http\Controllers;

use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Services\RoleAdminService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandResult;
use App\Shared\Commands\CommandRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RoleAdminController
{
    public function __construct(
        private readonly AuthenticationContext $context,
        private readonly RoleAdminService $roles,
        private readonly CommandRunner $commands,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $principal = $this->context->requirePrincipal();
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'purpose' => ['required', 'string', 'min:8', 'max:255'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
            'capabilities' => ['required', 'array', 'min:1', 'max:64'],
            'capabilities.*.capability' => ['required', 'string', 'max:120'],
            'capabilities.*.anchor_type' => ['required', 'string', 'max:24'],
            'capabilities.*.field_profile' => ['required', 'string', 'max:24'],
        ]);

        $payload = [
            'code' => (string) $data['code'],
            'name' => (string) $data['name'],
            'purpose' => (string) $data['purpose'],
            'owner' => $principal->userId(),
            'expires_at' => isset($data['expires_at']) ? (string) $data['expires_at'] : null,
            'capabilities' => array_values((array) $data['capabilities']),
        ];

        $result = $this->commands->run(
            definition: $this->definition('iam.role.create', 'iam.role.created'),
            payload: $payload,
            operation: function () use ($payload): array {
                $role = $this->roles->create($payload);

                return [
                    'http_status' => 201,
                    'body' => $role,
                    'resource_type' => 'role',
                    'resource_id' => (string) $role['code'],
                ];
            },
            idempotencyKey: (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result, created: true);
    }

    public function copy(Request $request, string $id): JsonResponse
    {
        $principal = $this->context->requirePrincipal();
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'purpose' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        $payload = [
            'source_role' => $id,
            'code' => (string) $data['code'],
            'name' => (string) $data['name'],
            'purpose' => (string) $data['purpose'],
            'owner' => $principal->userId(),
        ];

        $result = $this->commands->run(
            definition: $this->definition('iam.role.copy', 'iam.role.copied'),
            payload: $payload,
            operation: function () use ($payload, $id): array {
                $role = $this->roles->copy($id, $payload);

                return [
                    'http_status' => 201,
                    'body' => $role,
                    'resource_type' => 'role',
                    'resource_id' => (string) $role['code'],
                ];
            },
            idempotencyKey: (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result, created: true);
    }

    public function capabilities(Request $request, string $id): JsonResponse
    {
        $principal = $this->context->requirePrincipal();
        $data = $request->validate([
            'capabilities' => ['required', 'array', 'min:1', 'max:64'],
            'capabilities.*.capability' => ['required', 'string', 'max:120'],
            'capabilities.*.anchor_type' => ['required', 'string', 'max:24'],
            'capabilities.*.field_profile' => ['required', 'string', 'max:24'],
        ]);

        $payload = [
            'role_code' => $id,
            'capabilities' => array_values((array) $data['capabilities']),
        ];

        $result = $this->commands->run(
            definition: $this->definition('iam.role.capabilities.replace', 'iam.role.capabilities.changed'),
            payload: $payload,
            operation: function () use ($payload, $id): array {
                $role = $this->roles->replaceCapabilities($id, $payload['capabilities']);

                return [
                    'http_status' => 200,
                    'body' => $role,
                    'resource_type' => 'role',
                    'resource_id' => (string) $role['code'],
                ];
            },
            idempotencyKey: (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result, created: false);
    }

    private function definition(string $name, string $auditAction): CommandDefinition
    {
        return new CommandDefinition(
            name: $name,
            capability: 'iam.role.manage',
            anchor: AnchorType::COMPANY,
            idempotent: true,
            invariants: [],
            effect: null,
            auditAction: $auditAction,
        );
    }

    private function respond(CommandResult $result, bool $created): JsonResponse
    {
        $response = $created && ! $result->replayed
            ? ApiResponse::created($result->body)
            : ApiResponse::ok($result->body);

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }
}
