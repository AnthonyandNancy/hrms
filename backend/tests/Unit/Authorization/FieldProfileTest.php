<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Shared\Authorization\FieldProfile;
use App\Shared\Authorization\FieldSet;
use App\Shared\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

class FieldProfileTest extends TestCase
{
    public function test_profiles_have_strictly_ordered_ranks(): void
    {
        $this->assertSame(0, FieldProfile::P0_PUBLIC->rank());
        $this->assertSame(1, FieldProfile::P1_INTERNAL->rank());
        $this->assertSame(2, FieldProfile::P2_SENSITIVE->rank());
        $this->assertSame(3, FieldProfile::P3_RESTRICTED->rank());
        $this->assertTrue(FieldProfile::P2_SENSITIVE->isAtMost(FieldProfile::P2_SENSITIVE));
        $this->assertFalse(FieldProfile::P3_RESTRICTED->isAtMost(FieldProfile::P2_SENSITIVE));
    }

    public function test_field_set_derives_visibility_filtering_and_export_flags(): void
    {
        $set = $this->fieldSet(exportUnlocked: false);

        $this->assertSame(['name', 'email', 'id_number'], $set->visible);
        $this->assertSame(['name', 'email'], $set->filterable);
        $this->assertSame(['name'], $set->exportable);
        $this->assertTrue($set->isVisible('id_number'));
        $this->assertFalse($set->isVisible('salary'));
        $this->assertFalse($set->isExportable('id_number'));
    }

    public function test_export_capability_unlocks_p1_and_p2_but_never_p3(): void
    {
        $set = $this->fieldSet(exportUnlocked: true);

        $this->assertSame(['name', 'email', 'id_number'], $set->exportable);
        $this->assertFalse($set->isExportable('salary'));
    }

    public function test_mask_keeps_p0_masks_p1_and_p2_and_never_returns_p3(): void
    {
        $row = [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'id_number' => '110101199001011234',
            'salary' => '999999',
        ];

        $masked = $this->fieldSet()->mask($row);

        $this->assertSame('Ada Lovelace', $masked['name']);
        $this->assertNotSame('ada@example.test', $masked['email']);
        $this->assertStringContainsString('@example.test', (string) $masked['email']);
        $this->assertNotSame('110101199001011234', $masked['id_number']);
        $this->assertArrayNotHasKey('salary', $masked);
    }

    public function test_filtering_by_p2_or_p3_throws_domain_validation_error(): void
    {
        $set = $this->fieldSet();

        foreach (['id_number', 'salary', 'unknown_field'] as $field) {
            try {
                $set->assertFilterable($field);
                $this->fail("Field {$field} must not be filterable.");
            } catch (ValidationException $exception) {
                $this->assertSame('FIELD_NOT_FILTERABLE', $exception->details()['reason'] ?? null);
            }
        }
    }

    private function fieldSet(bool $exportUnlocked = false): FieldSet
    {
        return new FieldSet([
            'name' => FieldProfile::P0_PUBLIC,
            'email' => FieldProfile::P1_INTERNAL,
            'id_number' => FieldProfile::P2_SENSITIVE,
            'salary' => FieldProfile::P3_RESTRICTED,
        ], $exportUnlocked);
    }
}
