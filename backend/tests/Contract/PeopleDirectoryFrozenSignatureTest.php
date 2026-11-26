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
            'employmentForUser' => [['int'], '?int'],
            'managedEmploymentIds' => [['int', 'string'], 'array'],
            'employmentIdsAsOf' => [['string'], 'array'],
            'employmentSnapshot' => [['int', 'string'], '?'.EmploymentSnapshot::class],
            'employmentExistsInTenant' => [['int'], 'bool'],
        ];

        $this->assertFrozenSignatures(PeopleDirectory::class, $expected);
    }

    public function test_organization_directory_methods_match_the_interface_freeze(): void
    {
        $expected = [
            'orgUnitIds' => [['array', 'string'], 'array'],
            'subtreeIds' => [['int', 'string'], 'array'],
            'tree' => [['string'], 'array'],
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
