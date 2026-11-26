<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\Authorization\BindingGrant;
use App\Shared\Authorization\Contracts\AccessSubjectStateDirectory;
use App\Shared\Authorization\Contracts\AuthorityBindingRepository;
use App\Shared\Authorization\Contracts\CaseMembershipDirectory;
use App\Shared\Authorization\Contracts\ElevationGrantDirectory;
use App\Shared\Authorization\Contracts\EmploymentManagerDirectory;
use App\Shared\Authorization\Contracts\OrganizationScopeDirectory;
use App\Shared\Authorization\Contracts\PayrollRunMembershipDirectory;
use App\Shared\Authorization\Contracts\PersonEmploymentDirectory;
use App\Shared\Authorization\Contracts\SensitiveAccessAuditor;
use App\Shared\Authorization\EmploymentRef;
use App\Shared\Authorization\ResourceRef;
use App\Shared\Commands\CommandRunner;
use App\Shared\Commands\Invariant;
use App\Shared\Files\FileMetadata;
use App\Shared\Files\FileMetadataReader;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Observability\OutboxRecorder;
use App\Shared\Projections\ProjectionRequest;
use App\Shared\RequestContext;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\CompanyBusinessDate;
use App\Shared\Time\TenantBusinessDate;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionNamedType;
use SplFileInfo;

final class SharedSingleCompanyContractTest extends TestCase
{
    private const FORBIDDEN = '/tenant_id|legal_entity_id|LEGAL_ENTITY|tenantId\(|legalEntityId/';

    public function test_shared_source_has_no_legacy_scope_identifiers(): void
    {
        $root = dirname(__DIR__, 3).'/app/Shared';
        $violations = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            foreach (preg_split('/\R/', (string) file_get_contents($file->getPathname())) ?: [] as $index => $line) {
                if (preg_match(self::FORBIDDEN, $line) === 1) {
                    $violations[] = str_replace('\\', '/', $file->getPathname()).':'.($index + 1);
                }
            }
        }

        $this->assertSame([], $violations, "Legacy scope identifiers remain:\n".implode("\n", $violations));
    }

    public function test_scope_value_objects_carry_no_tenant_or_legal_entity_properties(): void
    {
        foreach ([BindingGrant::class, ResourceRef::class, EmploymentRef::class, FileMetadata::class, ProjectionRequest::class] as $class) {
            $properties = array_map(
                static fn ($property): string => $property->getName(),
                (new ReflectionClass($class))->getProperties(),
            );

            $this->assertNotContains('tenantId', $properties, $class);
            $this->assertNotContains('legalEntityId', $properties, $class);
        }
    }

    public function test_authorization_directories_drop_tenant_parameters_and_legal_entity_methods(): void
    {
        $this->assertMethod(AccessSubjectStateDirectory::class, 'isEnabled', ['userId'], 'bool');
        $this->assertMethod(AccessSubjectStateDirectory::class, 'currentAuthVersion', ['userId'], 'int');
        $this->assertMethod(ElevationGrantDirectory::class, 'activeCapabilities', ['userId', 'grantId'], 'array');
        $this->assertMethod(AuthorityBindingRepository::class, 'effectiveBindings', ['principalId', 'capabilityCode', 'authVersion', 'businessDate'], 'array');
        $this->assertMethod(PersonEmploymentDirectory::class, 'findEmployment', ['employmentId'], '?'.EmploymentRef::class);
        $this->assertMethod(PersonEmploymentDirectory::class, 'employmentIdsForUser', ['userId', 'businessDate'], 'array');
        $this->assertMethod(PersonEmploymentDirectory::class, 'managerEmploymentIds', ['userId', 'businessDate'], 'array');
        $this->assertMethod(PersonEmploymentDirectory::class, 'employmentIdsForOrgUnits', ['orgUnitIds', 'businessDate'], 'array');
        $this->assertMethod(OrganizationScopeDirectory::class, 'orgUnitExists', ['orgUnitId'], 'bool');
        $this->assertMethod(OrganizationScopeDirectory::class, 'orgUnitAncestors', ['orgUnitId'], 'array');
        $this->assertMethod(OrganizationScopeDirectory::class, 'descendantOrgUnitIds', ['orgUnitId'], 'array');
        $this->assertMethod(CaseMembershipDirectory::class, 'isMember', ['userId', 'caseId', 'capabilityCode'], 'bool');
        $this->assertMethod(CaseMembershipDirectory::class, 'caseIdsForUser', ['userId', 'businessDate'], 'array');
        $this->assertMethod(PayrollRunMembershipDirectory::class, 'isMember', ['userId', 'payrollRunId', 'capabilityCode'], 'bool');
        $this->assertMethod(PayrollRunMembershipDirectory::class, 'runIdsForUser', ['userId', 'businessDate'], 'array');
        $this->assertMethod(EmploymentManagerDirectory::class, 'managerUserIdForEmployment', ['employmentId', 'businessDate'], '?int');

        $this->assertFalse((new ReflectionClass(CaseMembershipDirectory::class))->hasMethod('legalEntityForCase'));
        $this->assertFalse((new ReflectionClass(PayrollRunMembershipDirectory::class))->hasMethod('legalEntityForRun'));
        $this->assertFalse((new ReflectionClass(PersonEmploymentDirectory::class))->hasMethod('employmentContext'));
    }

    public function test_observability_and_command_ports_drop_tenant_arguments(): void
    {
        $this->assertMethod(AuditRecorder::class, 'record', ['actorUserId', 'action', 'aggregateType', 'aggregateId', 'before', 'after', 'metadata'], 'void');
        $this->assertMethod(OutboxRecorder::class, 'record', ['eventType', 'aggregateType', 'aggregateId', 'aggregateVersion', 'payload'], 'void');
        $this->assertMethod(Invariant::class, 'assert', ['payload'], 'void');
        $this->assertMethod(CommandRunner::class, 'run', ['definition', 'payload', 'operation', 'idempotencyKey'], 'App\Shared\Commands\CommandResult');
        $this->assertMethod(RequestContext::class, 'setActor', ['userId', 'employmentId'], 'void');
    }

    public function test_sensitive_access_auditor_uses_the_approved_shape(): void
    {
        $this->assertMethod(SensitiveAccessAuditor::class, 'record', ['userId', 'capabilityCode', 'resourceType', 'resourceId', 'fields', 'context'], 'void');

        $parameters = (new ReflectionClass(SensitiveAccessAuditor::class))->getMethod('record')->getParameters();
        $types = array_map(static fn ($parameter): string => self::typeName($parameter->getType()), $parameters);

        $this->assertSame(['int', 'string', 'string', '?int', 'array', 'array'], $types);
        $this->assertNull($parameters[3]->getDefaultValue());
        $this->assertSame([], $parameters[4]->getDefaultValue());
        $this->assertSame([], $parameters[5]->getDefaultValue());
    }

    public function test_file_metadata_and_projection_request_have_no_scope_argument(): void
    {
        $this->assertMethod(FileMetadataReader::class, 'metadata', ['fileObjectId'], '?'.FileMetadata::class);
        $this->assertMethod(RequestContext::class, 'setActor', ['userId', 'employmentId'], 'void');

        $projectionParameters = array_map(
            static fn ($parameter): string => $parameter->getName(),
            (new ReflectionClass(ProjectionRequest::class))->getConstructor()?->getParameters() ?? [],
        );

        $this->assertNotContains('tenantId', $projectionParameters);
    }

    public function test_company_business_date_replaces_the_tenant_business_date(): void
    {
        $this->assertTrue(class_exists(CompanyBusinessDate::class));
        $this->assertFalse(class_exists(TenantBusinessDate::class));

        $this->assertMethod(CompanyBusinessDate::class, 'today', [], BusinessDate::class);
        $this->assertMethod(CompanyBusinessDate::class, 'resolve', ['value', 'field'], BusinessDate::class);

        $field = (new ReflectionClass(CompanyBusinessDate::class))->getMethod('resolve')->getParameters()[1];

        $this->assertSame('as_of', $field->getDefaultValue());
    }

    /**
     * @param  list<string>  $parameterNames
     */
    private function assertMethod(string $class, string $method, array $parameterNames, string $returnType): void
    {
        $reflection = new ReflectionClass($class);
        $this->assertTrue($reflection->hasMethod($method), "{$class}::{$method} is missing.");

        $target = $reflection->getMethod($method);
        $actual = array_map(static fn ($parameter): string => $parameter->getName(), $target->getParameters());

        $this->assertSame($parameterNames, $actual, "{$class}::{$method} parameters changed.");
        $this->assertSame($returnType, self::typeName($target->getReturnType()), "{$class}::{$method} return type changed.");
    }

    private static function typeName(?\ReflectionType $type): string
    {
        if (! $type instanceof ReflectionNamedType) {
            return 'complex';
        }

        return ($type->allowsNull() && $type->getName() !== 'null' ? '?' : '').$type->getName();
    }
}
