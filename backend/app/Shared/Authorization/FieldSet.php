<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

use App\Shared\Exceptions\ValidationException;

/**
 * Projection result for one row. Visible, filterable and exportable sets are
 * derived from per-field sensitivity and the caller's export capability.
 */
final class FieldSet
{
    /** @var array<string, FieldProfile> */
    private array $profiles;

    /** @var list<string> */
    public readonly array $visible;

    /** @var list<string> */
    public readonly array $filterable;

    /** @var list<string> */
    public readonly array $exportable;

    /** @var list<string> */
    public readonly array $hidden;

    /**
     * @param  array<string, FieldProfile>  $profiles
     */
    public function __construct(array $profiles, private readonly bool $exportUnlocked = false)
    {
        $this->profiles = $profiles;
        $this->visible = array_keys(array_filter(
            $profiles,
            static fn (FieldProfile $profile): bool => ! $profile->isHidden(),
        ));
        $this->filterable = array_keys(array_filter(
            $profiles,
            static fn (FieldProfile $profile): bool => $profile->isFilterable(),
        ));
        $this->exportable = array_keys(array_filter(
            $profiles,
            fn (FieldProfile $profile): bool => $this->isExportableProfile($profile),
        ));
        $this->hidden = array_keys(array_filter(
            $profiles,
            static fn (FieldProfile $profile): bool => $profile->isHidden(),
        ));
    }

    /**
     * @return array<string, FieldProfile>
     */
    public function profiles(): array
    {
        return $this->profiles;
    }

    /**
     * @return list<string>
     */
    public function visible(): array
    {
        return $this->visible;
    }

    /**
     * @return list<string>
     */
    public function filterable(): array
    {
        return $this->filterable;
    }

    /**
     * @return list<string>
     */
    public function exportable(): array
    {
        return $this->exportable;
    }

    /**
     * @return list<string>
     */
    public function hidden(): array
    {
        return $this->hidden;
    }

    public function isVisible(string $field): bool
    {
        return isset($this->profiles[$field]) && ! $this->profiles[$field]->isHidden();
    }

    public function isFilterable(string $field): bool
    {
        return isset($this->profiles[$field]) && $this->profiles[$field]->isFilterable();
    }

    public function isExportable(string $field): bool
    {
        return isset($this->profiles[$field]) && $this->isExportableProfile($this->profiles[$field]);
    }

    public function profileFor(string $field): ?FieldProfile
    {
        return $this->profiles[$field] ?? null;
    }

    public function assertVisible(string $field): void
    {
        if (! $this->isVisible($field)) {
            throw new ValidationException(
                ['field' => [$field]],
                'The requested field is not available.',
                ['reason' => 'FIELD_NOT_VISIBLE'],
            );
        }
    }

    public function assertFilterable(string $field): void
    {
        if (! $this->isFilterable($field)) {
            throw new ValidationException(
                ['field' => [$field]],
                'This field cannot be used for filtering or sorting.',
                ['reason' => 'FIELD_NOT_FILTERABLE'],
            );
        }
    }

    public function assertSortable(string $field): void
    {
        $this->assertFilterable($field);
    }

    /**
     * Remove hidden fields, then mask P1/P2 values. The method never mutates
     * the input and never returns a P3 key.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function mask(array $row): array
    {
        $masked = [];

        foreach ($row as $field => $value) {
            if (! is_string($field) || ! isset($this->profiles[$field])) {
                continue;
            }

            $profile = $this->profiles[$field];

            if ($profile->isHidden()) {
                continue;
            }

            $masked[$field] = match ($profile) {
                FieldProfile::P0_PUBLIC => $value,
                FieldProfile::P1_INTERNAL => $this->maskInternal($field, $value),
                FieldProfile::P2_SENSITIVE => $this->maskSensitive($value),
                FieldProfile::P3_RESTRICTED => null,
            };
        }

        return $masked;
    }

    /**
     * Project a list of rows consistently with one field set. Used by list
     * endpoints after constrain() so pagination and totals remain correct.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function maskAll(array $rows): array
    {
        return array_map(fn (array $row): array => $this->mask($row), $rows);
    }

    /**
     * @return list<string>
     */
    public function sensitiveFieldsReturned(): array
    {
        return array_keys(array_filter(
            $this->profiles,
            static fn (FieldProfile $profile): bool => in_array($profile, [FieldProfile::P2_SENSITIVE, FieldProfile::P3_RESTRICTED], true),
        ));
    }

    private function isExportableProfile(FieldProfile $profile): bool
    {
        if ($profile->isHidden()) {
            return false;
        }

        if ($profile === FieldProfile::P0_PUBLIC) {
            return true;
        }

        return $this->exportUnlocked;
    }

    private function maskInternal(string $field, mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        $at = strpos($value, '@');

        if ($at !== false && $at > 0) {
            $local = substr($value, 0, $at);
            $domain = substr($value, $at);
            $prefix = mb_substr($local, 0, 1);

            return $prefix.'***'.$domain;
        }

        if (preg_match('/^\d{6,}$/', $value) === 1) {
            return str_repeat('*', max(0, strlen($value) - 4)).substr($value, -4);
        }

        if (mb_strlen($value) <= 2) {
            return '**';
        }

        return mb_substr($value, 0, 1).str_repeat('*', max(1, mb_strlen($value) - 1));
    }

    private function maskSensitive(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        $length = mb_strlen($value);
        $suffix = $length > 4 ? mb_substr($value, -4) : '';

        return str_repeat('*', max(1, $length - mb_strlen($suffix))).$suffix;
    }
}
