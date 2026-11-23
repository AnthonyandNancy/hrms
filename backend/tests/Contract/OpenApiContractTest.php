<?php

declare(strict_types=1);

namespace Tests\Contract;

use Tests\TestCase;

final class OpenApiContractTest extends TestCase
{
    public function test_openapi_declares_every_new_route_and_envelope(): void
    {
        $path = dirname(__DIR__, 3).'/docs/openapi/openapi.yaml';
        $this->assertFileExists($path);

        $contents = (string) file_get_contents($path);
        $this->assertStringContainsString('openapi: 3.1.0', $contents);

        foreach ([
            '/employees:', '/employees/{employment_id}:', '/persons:', '/employments:',
            '/employments/{employment_id}/assignments:', '/employments/{employment_id}/terms:',
            '/employments/{employment_id}/documents:', '/employments/{employment_id}/timeline:',
            '/org-units/tree:', '/org-units:', '/org-units/{id}/move:', '/org-units/{id}/impact:',
            '/documents/{document_id}/download:',
        ] as $route) {
            $this->assertStringContainsString($route, $contents, "OpenAPI missing {$route}");
        }

        foreach (['Envelope:', 'Paginated:', 'EmploymentSnapshot:', 'Assignment:', 'OrgUnit:', 'Error:'] as $schema) {
            $this->assertStringContainsString($schema, $contents, "OpenAPI missing schema {$schema}");
        }
    }
}
