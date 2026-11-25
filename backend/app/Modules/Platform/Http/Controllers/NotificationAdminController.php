<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\Platform\Models\NotificationDelivery;
use App\Modules\Platform\Models\NotificationOutbox;
use App\Modules\Platform\Models\NotificationTemplate;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandResult;
use App\Shared\Commands\CommandRunner;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Projections\ProjectionResponder;
use App\Shared\Projections\ProjectionResult;
use App\Shared\RequestContext;
use App\Shared\Time\Clock;
use App\Shared\Time\TenantBusinessDate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Notification templates, outbox dispatch and delivery retry.
 *
 * Dispatch is idempotent at two levels: the command ledger replays an
 * identical request, and the unique (tenant, event_key) outbox constraint
 * collapses different Idempotency-Keys for the same business event.
 */
final class NotificationAdminController
{
    public function __construct(
        private readonly AuthenticationContext $context,
        private readonly CommandRunner $commands,
        private readonly ProjectionResponder $projections,
        private readonly TenantBusinessDate $businessDates,
        private readonly Clock $clock,
    ) {}

    public function templates(Request $request): JsonResponse
    {
        $tenantId = $this->context->requirePrincipal()->tenantId();
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = (int) ($data['page'] ?? 1);
        $pageSize = (int) ($data['page_size'] ?? 20);
        $query = NotificationTemplate::query()->where('tenant_id', $tenantId);
        $total = (clone $query)->count();
        $items = $query->orderBy('code')->forPage($page, $pageSize)->get()
            ->map(fn (NotificationTemplate $template): array => $this->templateRow($template))
            ->all();

        return $this->projections->ok(new ProjectionResult(
            items: $items,
            page: $page,
            pageSize: $pageSize,
            total: $total,
            asOf: $this->businessDates->today($tenantId)->value(),
            freshness: ProjectionResult::FRESHNESS_LIVE,
            sourceHash: hash('sha256', json_encode($items, JSON_THROW_ON_ERROR)),
            partial: false,
            partialReason: null,
            drilldown: ['resource' => 'notification_templates'],
        ));
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Z][A-Z0-9_]{2,63}$/'],
            'channel' => ['required', 'string', 'in:IN_APP,EMAIL,WECHAT_WORK'],
            'subject' => ['required', 'string', 'min:1', 'max:200'],
            'body' => ['required', 'string', 'min:1', 'max:10000'],
        ]);

        $result = $this->commands->run(
            definition: $this->definition('notification.template.create', 'notification.template.created'),
            payload: $data,
            operation: function () use ($data): array {
                $tenantId = $this->context->requirePrincipal()->tenantId();
                $exists = NotificationTemplate::query()
                    ->where('tenant_id', $tenantId)
                    ->where('code', (string) $data['code'])
                    ->exists();

                if ($exists) {
                    throw new ConflictException('This notification template already exists.', ['reason' => 'NOTIFICATION_TEMPLATE_EXISTS']);
                }

                /** @var NotificationTemplate $template */
                $template = NotificationTemplate::query()->create([
                    'tenant_id' => $tenantId,
                    'code' => (string) $data['code'],
                    'channel' => (string) $data['channel'],
                    'subject' => (string) $data['subject'],
                    'body' => (string) $data['body'],
                    'version' => 1,
                    'active' => true,
                ]);

                return [
                    'http_status' => 201,
                    'body' => $this->templateRow($template),
                    'resource_type' => 'notification_template',
                    'resource_id' => (string) $template->id,
                ];
            },
            idempotencyKey: (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result, created: true);
    }

    public function dispatch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_key' => ['required', 'string', 'max:120'],
            'template_code' => ['required', 'string', 'max:64'],
            'recipient_user_id' => ['required', 'integer', 'min:1'],
            'variables' => ['sometimes', 'array'],
            'variables.*' => ['scalar'],
        ]);

        $payload = [
            'event_key' => (string) $data['event_key'],
            'template_code' => (string) $data['template_code'],
            'recipient_user_id' => (int) $data['recipient_user_id'],
            'variables' => (array) ($data['variables'] ?? []),
        ];

        $result = $this->commands->run(
            definition: $this->definition('notification.delivery.dispatch', 'notification.delivery.dispatched'),
            payload: $payload,
            operation: function () use ($payload): array {
                $tenantId = $this->context->requirePrincipal()->tenantId();
                /** @var NotificationTemplate|null $template */
                $template = NotificationTemplate::query()
                    ->where('tenant_id', $tenantId)
                    ->where('code', $payload['template_code'])
                    ->where('active', true)
                    ->first();

                if ($template === null) {
                    throw new ValidationException(
                        ['template_code' => ['The template is not active for this tenant.']],
                        'The given data was invalid.',
                        ['reason' => 'UNKNOWN_NOTIFICATION_TEMPLATE'],
                    );
                }

                /** @var NotificationOutbox|null $already */
                $already = NotificationOutbox::query()
                    ->where('tenant_id', $tenantId)
                    ->where('event_key', $payload['event_key'])
                    ->first();

                if ($already !== null) {
                    return $this->existingOutboxResult($tenantId, $already, $payload);
                }

                try {
                    /** @var NotificationOutbox $outbox */
                    $outbox = NotificationOutbox::query()->create([
                        'tenant_id' => $tenantId,
                        'event_key' => $payload['event_key'],
                        'template_code' => (string) $template->code,
                        'recipient_user_id' => $payload['recipient_user_id'],
                        'payload_json' => ['variables' => $payload['variables']],
                        'status' => NotificationOutbox::STATUS_DISPATCHED,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    /** @var NotificationOutbox|null $existing */
                    $existing = NotificationOutbox::query()
                        ->where('tenant_id', $tenantId)
                        ->where('event_key', $payload['event_key'])
                        ->first();

                    if ($existing === null) {
                        throw new ConflictException('The notification event is already being dispatched.', ['reason' => 'NOTIFICATION_EVENT_IN_FLIGHT']);
                    }

                    return $this->existingOutboxResult($tenantId, $existing, $payload);
                }

                /** @var NotificationDelivery $delivery */
                $delivery = NotificationDelivery::query()->create([
                    'tenant_id' => $tenantId,
                    'notification_outbox_id' => (int) $outbox->id,
                    'template_code' => (string) $template->code,
                    'recipient_user_id' => $payload['recipient_user_id'],
                    'channel' => (string) $template->channel,
                    'status' => NotificationDelivery::STATUS_SENT,
                    'attempts' => 1,
                    'last_error' => null,
                    'sent_at' => $this->clock->now(),
                ]);

                // The reference channel records an in-app notification row.
                // Platform writes only its own delivery evidence; Engagement
                // owns in-app notification rows and is notified through the
                // shared outbox contract.
                app(\App\Shared\Observability\OutboxRecorder::class)->record(
                    tenantId: $tenantId,
                    eventType: 'platform.notification.delivery.sent',
                    aggregateType: 'notification_delivery',
                    aggregateId: (string) $delivery->id,
                    aggregateVersion: 1,
                    payload: [
                        'delivery_id' => (int) $delivery->id,
                        'template_code' => (string) $template->code,
                        'channel' => (string) $template->channel,
                        'recipient_user_id' => $payload['recipient_user_id'],
                    ],
                );

                return [
                    'http_status' => 201,
                    'body' => $this->deliveryRow($delivery),
                    'resource_type' => 'notification_delivery',
                    'resource_id' => (string) $delivery->id,
                ];
            },
            idempotencyKey: (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result, created: true);
    }

    public function deliveries(Request $request): JsonResponse
    {
        $tenantId = $this->context->requirePrincipal()->tenantId();
        $data = $request->validate([
            'status' => ['sometimes', 'nullable', 'string', 'in:PENDING,SENT,FAILED'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = (int) ($data['page'] ?? 1);
        $pageSize = (int) ($data['page_size'] ?? 20);
        $query = NotificationDelivery::query()->where('tenant_id', $tenantId);

        if (isset($data['status'])) {
            $query->where('status', (string) $data['status']);
        }

        $total = (clone $query)->count();
        $items = $query->orderByDesc('id')->forPage($page, $pageSize)->get()
            ->map(fn (NotificationDelivery $delivery): array => $this->deliveryRow($delivery))
            ->all();

        return $this->projections->ok(new ProjectionResult(
            items: $items,
            page: $page,
            pageSize: $pageSize,
            total: $total,
            asOf: $this->businessDates->today($tenantId)->value(),
            freshness: ProjectionResult::FRESHNESS_LIVE,
            sourceHash: hash('sha256', json_encode($items, JSON_THROW_ON_ERROR)),
            partial: false,
            partialReason: null,
            drilldown: ['resource' => 'notification_deliveries', 'filters' => ['status' => $data['status'] ?? null]],
        ));
    }

    public function retry(Request $request, int $id): JsonResponse
    {
        $result = $this->commands->run(
            definition: $this->definition('notification.delivery.retry', 'notification.delivery.retried'),
            payload: ['delivery_id' => $id],
            operation: function () use ($id): array {
                $tenantId = $this->context->requirePrincipal()->tenantId();
                /** @var NotificationDelivery|null $delivery */
                $delivery = NotificationDelivery::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($id)
                    ->lockForUpdate()
                    ->first();

                if ($delivery === null) {
                    throw new NotFoundOrForbiddenException;
                }

                if ((string) $delivery->status === NotificationDelivery::STATUS_SENT) {
                    throw new ConflictException('This delivery has already been sent.', ['reason' => 'DELIVERY_ALREADY_SENT']);
                }

                $delivery->forceFill([
                    'status' => NotificationDelivery::STATUS_SENT,
                    'attempts' => (int) $delivery->attempts + 1,
                    'last_error' => null,
                    'sent_at' => $this->clock->now(),
                ])->save();

                return [
                    'http_status' => 200,
                    'body' => $this->deliveryRow($delivery->refresh()),
                    'resource_type' => 'notification_delivery',
                    'resource_id' => (string) $delivery->id,
                ];
            },
            idempotencyKey: (string) $request->header('Idempotency-Key', ''),
        );

        $response = ApiResponse::ok($result->body);

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    /**
     * @param  array{event_key: string, template_code: string, recipient_user_id: int, variables: array<string, mixed>}  $payload
     * @return array{http_status: int, body: array<string, mixed>, resource_type: string, resource_id: string}
     */
    private function existingOutboxResult(int $tenantId, NotificationOutbox $existing, array $payload): array
    {
        $sameRecipient = (int) $existing->recipient_user_id === $payload['recipient_user_id'];
        $sameTemplate = (string) $existing->template_code === $payload['template_code'];
        $sameVariables = (array) ($existing->payload_json['variables'] ?? []) === $payload['variables'];

        if (! $sameRecipient || ! $sameTemplate || ! $sameVariables) {
            throw new ConflictException(
                'The event key was already used with a different notification payload.',
                ['reason' => 'EVENT_KEY_PAYLOAD_MISMATCH'],
            );
        }

        $delivery = NotificationDelivery::query()
            ->where('tenant_id', $tenantId)
            ->where('notification_outbox_id', (int) $existing->id)
            ->firstOrFail();

        return [
            'http_status' => 200,
            'body' => $this->deliveryRow($delivery),
            'resource_type' => 'notification_delivery',
            'resource_id' => (string) $delivery->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function templateRow(NotificationTemplate $template): array
    {
        return [
            'id' => (int) $template->id,
            'code' => (string) $template->code,
            'channel' => (string) $template->channel,
            'subject' => (string) $template->subject,
            'body' => (string) $template->body,
            'version' => (int) $template->version,
            'active' => (bool) $template->active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deliveryRow(NotificationDelivery $delivery): array
    {
        return [
            'id' => (int) $delivery->id,
            'delivery_id' => (int) $delivery->id,
            'template_code' => (string) $delivery->template_code,
            'recipient_user_id' => (int) $delivery->recipient_user_id,
            'channel' => (string) $delivery->channel,
            'status' => (string) $delivery->status,
            'attempts' => (int) $delivery->attempts,
            'last_error' => $delivery->last_error !== null ? (string) $delivery->last_error : null,
            'sent_at' => $delivery->sent_at?->toIso8601ZuluString(),
        ];
    }

    private function definition(string $name, string $auditAction): CommandDefinition
    {
        return new CommandDefinition(
            name: $name,
            capability: $name === 'notification.template.create' ? 'notification.template.manage' : 'notification.delivery.manage',
            anchor: AnchorType::LEGAL_ENTITY,
            idempotent: true,
            invariants: [],
            effect: null,
            auditAction: $auditAction,
        );
    }

    private function respond(CommandResult $result, bool $created): JsonResponse
    {
        // The operation may intentionally return 200 for an already-consumed
        // business event even though the command was fresh. Honor that status
        // rather than assuming every non-replay is a creation.
        $response = $created && ! $result->replayed && $result->status === 201
            ? ApiResponse::created($result->body)
            : ApiResponse::ok($result->body);

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }
}
