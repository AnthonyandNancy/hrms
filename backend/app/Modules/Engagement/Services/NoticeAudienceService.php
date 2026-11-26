<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Services;

use App\Modules\IAM\Contracts\UserAudienceDirectory;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\People\Contracts\NoticeAudienceDirectory;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\CompanyBusinessDate;

final class NoticeAudienceService
{
    public function __construct(
        private readonly OrganizationDirectory $organization,
        private readonly NoticeAudienceDirectory $peopleAudience,
        private readonly UserAudienceDirectory $users,
        private readonly CompanyBusinessDate $businessDates,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{audience_json: array<string, mixed>, recipients: list<int>, audience_count: int}
     */
    public function resolve(array $input, ?string $on = null): array
    {
        $businessDate = $this->businessDates->resolve($on);
        $date = $businessDate->value();
        $type = strtoupper((string) ($input['audience'] ?? ''));
        $label = trim((string) ($input['audienceLabel'] ?? $input['audience_label'] ?? ''));

        if (! in_array($type, ['ALL', 'ORG', 'ROLE'], true)) {
            throw new ValidationException(
                ['audience' => ['Audience must be ALL, ORG or ROLE.']],
                'The given data was invalid.',
                ['reason' => 'INVALID_AUDIENCE'],
            );
        }

        $orgUnitIds = $this->ids($input['orgUnitIds'] ?? $input['org_unit_ids'] ?? []);
        $roleCodes = $this->roleCodes($input['roleCodes'] ?? $input['role_codes'] ?? []);

        if ($label === '') {
            $label = match ($type) {
                'ALL' => 'All employees',
                'ORG' => 'Selected organization',
                'ROLE' => 'Selected roles',
            };
        }

        if ($type === 'ORG') {
            if ($orgUnitIds === []) {
                throw new ValidationException(
                    ['orgUnitIds' => ['At least one organization unit is required.']],
                    'The given data was invalid.',
                    ['reason' => 'AUDIENCE_ORG_REQUIRED'],
                );
            }

            $valid = $this->organization->orgUnitIds($orgUnitIds, $date);

            if (count($valid) !== count($orgUnitIds)) {
                throw new ValidationException(
                    ['orgUnitIds' => ['Every audience organization must exist and be effective on the business date.']],
                    'The given data was invalid.',
                    ['reason' => 'AUDIENCE_ORG_INVALID'],
                );
            }

            $employmentIds = $this->peopleAudience->employmentIdsForOrgUnits($orgUnitIds, $date);
            $recipients = $this->filterActive(
                $this->peopleAudience->userIdsForEmployments($employmentIds, $date),
            );
        } elseif ($type === 'ROLE') {
            if ($roleCodes === []) {
                throw new ValidationException(
                    ['roleCodes' => ['At least one role code is required.']],
                    'The given data was invalid.',
                    ['reason' => 'AUDIENCE_ROLE_REQUIRED'],
                );
            }

            $recipients = $this->filterActive(
                $this->users->userIdsWithRoles($roleCodes, $date),
            );
        } else {
            $recipients = $this->users->activeUserIds();
        }

        return [
            'audience_json' => [
                'type' => $type,
                'label' => mb_substr($label, 0, 120),
                'org_unit_ids' => $type === 'ORG' ? $orgUnitIds : [],
                'role_codes' => $type === 'ROLE' ? $roleCodes : [],
            ],
            'recipients' => array_values(array_unique($recipients)),
            'audience_count' => count(array_unique($recipients)),
        ];
    }

    /**
     * @param  array<string, mixed>  $audience
     * @return list<int>
     */
    public function recipientsForSnapshot(array $audience, string $businessDate): array
    {
        $type = strtoupper((string) ($audience['type'] ?? ''));

        return match ($type) {
            'ALL' => $this->users->activeUserIds(),
            'ROLE' => $this->filterActive(
                $this->users->userIdsWithRoles($this->roleCodes($audience['role_codes'] ?? []), $businessDate),
            ),
            'ORG' => $this->filterActive(
                $this->peopleAudience->userIdsForEmployments(
                    $this->peopleAudience->employmentIdsForOrgUnits(
                        $this->ids($audience['org_unit_ids'] ?? []),
                        $businessDate,
                    ),
                    $businessDate,
                ),
            ),
            default => [],
        };
    }

    /**
     * @param  list<int>  $recipients
     * @return list<int>
     */
    private function filterActive(array $recipients): array
    {
        $active = array_fill_keys($this->users->activeUserIds(), true);

        return array_values(array_filter($recipients, static fn (int $id): bool => isset($active[$id])));
    }

    /**
     * @return list<int>
     */
    private function ids(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $item) {
            if (is_numeric($item) && (int) $item > 0) {
                $ids[] = (int) $item;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<string>
     */
    private function roleCodes(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $codes = [];

        foreach ($value as $item) {
            if (! is_string($item) || preg_match('/^[A-Z][A-Z0-9_]{2,63}$/', $item) !== 1) {
                throw new ValidationException(
                    ['roleCodes' => ['Role codes must be structural uppercase codes.']],
                    'The given data was invalid.',
                    ['reason' => 'AUDIENCE_ROLE_INVALID'],
                );
            }

            $codes[] = $item;
        }

        return array_values(array_unique($codes));
    }
}
