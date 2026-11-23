<?php

declare(strict_types=1);

namespace Tests\Contract;

use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\People\Contracts\EmploymentSnapshot;
use App\Modules\People\Contracts\PeopleDirectory;
use ReflectionNamedType;
use Tests\TestCase;

final class PeopleDirectoryFrozenSignatureTest extends TestCase
{
    public function test_people_directory_methods_match_the_interface_freeze(): void
    {
        $expected = [
            'employmentForUser' => [['int', 'int'], '?int'],
            'managedEmploymentIds' => [['int', 'int', 'string'], 'array'],
            'employmentIdsForLegalEntities' => [['int', 'array', 'string'], 'array'],
            'employmentSnapshot' => [['int', 'int', 'string'], '?'.EmploymentSnapshot::class],
            'employmentExistsInTenant' => [['int', 'int'], 'bool'],
            'legalEntityIdsForEmployment' => [['int', 'int'], 'array'],
        ];

        $this->assertFrozenSignatures(PeopleDirectory::class, $expected);
    }

    public function test_organization_directory_methods_match_the_interface_freeze(): void
    {
        $expected = [
            'orgUnitIds' => [['int', 'array', 'string'], 'array'],
            'subtreeIds' => [['int', 'int', 'string'], 'array'],
            'tree' => [['int', 'int', 'string'], 'array'],
            'legalEntityOfOrg' => [['int', 'int', 'string'], '?int'],
        ];

        $this->assertFrozenSignatures(OrganizationDirectory::class, $expected);
    }

    /**
     * @param  class-string  $interface
     * @param  array<string, array{0: list<string>, 1: string}>  $expected
     */
    private function assertFrozenSignatures(string $interface, array $expected): void
    {
        $reflection = new \ReflectionClass($interface);
        $actual = [];

        foreach ($reflection->getMethods() as $method) {
            $parameters = [];

            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                $parameters[] = $type instanceof ReflectionNamedType
                    ? ($type->allowsNull() && $type->getName() !== 'mixed' ? '?' : '').$type->getName()
                    : 'complex';
            }

            $return = $method->getReturnType();
            $actual[$method->getName()] = [
                $parameters,
                $return instanceof ReflectionNamedType
                    ? ($return->allowsNull() && $return->getName() !== 'mixed' ? '?' : '').$return->getName()
                    : 'complex',
            ];
        }

        ksort($actual);
        ksort($expected);
        $this->assertSame($expected, $actual);
    }
}
