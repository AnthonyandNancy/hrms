<?php

declare(strict_types=1);

namespace App\Modules\Organization\Services;

use App\Modules\Organization\Contracts\CompanyProfileWriter;
use App\Modules\Organization\Models\CompanyProfile;
use App\Shared\Authorization\AnchorType;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandRunner;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Writes the single company profile row. The row is locked and the before/after
 * facts are captured in one transaction; `calendar_policy_json` is not touched
 * here because Time owns calendar publication.
 */
final class CompanyProfileWriterService implements CompanyProfileWriter
{
    public function __construct(
        private readonly CommandRunner $commands,
        private readonly AuditRecorder $audit,
    ) {}

    public function saveProfile(array $input, int $actorUserId, string $idempotencyKey): array
    {
        $payload = [
            'name' => $input['name'],
            'country_code' => strtoupper($input['country_code']),
            'timezone' => $input['timezone'],
            'currency_code' => strtoupper($input['currency_code']),
        ];

        $result = $this->commands->run(
            definition: new CommandDefinition(
                name: 'system.company_profile.update',
                capability: 'system.settings.manage',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'system.company_profile.updated',
            ),
            payload: $payload,
            operation: function () use ($payload, $actorUserId): array {
                return DB::transaction(function () use ($payload, $actorUserId): array {
                    if (CompanyProfile::query()->count() !== 1) {
                        throw new ValidationException(
                            ['company_profile' => ['The single company profile is missing or duplicated.']],
                            'The single company profile is missing or duplicated.',
                            ['reason' => 'COMPANY_PROFILE_CONFLICT'],
                        );
                    }

                    /** @var CompanyProfile $profile */
                    $profile = CompanyProfile::query()->lockForUpdate()->orderBy('id')->firstOrFail();
                    $before = [
                        'name' => (string) $profile->name,
                        'country_code' => (string) $profile->country_code,
                        'timezone' => (string) $profile->timezone,
                        'currency_code' => (string) $profile->currency_code,
                    ];

                    $profile->forceFill($payload)->save();

                    $after = [
                        'name' => (string) $profile->name,
                        'country_code' => (string) $profile->country_code,
                        'timezone' => (string) $profile->timezone,
                        'currency_code' => (string) $profile->currency_code,
                        'calendar_policy' => is_array($profile->calendar_policy_json) ? $profile->calendar_policy_json : null,
                    ];

                    $this->audit->record(
                        $actorUserId,
                        'system.company_profile.updated',
                        'company_profile',
                        (int) $profile->id,
                        before: $before,
                        after: $after,
                    );

                    return [
                        'http_status' => 200,
                        'body' => $after,
                        'resource_type' => 'company_profile',
                        'resource_id' => (string) $profile->id,
                    ];
                });
            },
            idempotencyKey: $idempotencyKey,
        );

        return $result->body;
    }
}
