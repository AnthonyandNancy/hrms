<?php

declare(strict_types=1);

namespace Tests\Contract;

use Tests\TestCase;

/**
 * Exercises the Node OpenAPI gate as a black box with temporary fixtures.
 */
class OpenApiLintCliTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/hrms-openapi-lint-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);

        parent::tearDown();
    }

    public function test_lint_accepts_the_repository_spec(): void
    {
        [$exitCode, $output] = $this->runLint(base_path('../docs/openapi/openapi.yaml'));

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('openapi-lint: OK', $output);
    }

    public function test_lint_fails_on_malformed_yaml(): void
    {
        $path = $this->fixture('malformed.yaml', "openapi: 3.1.0\ninfo:\n  title: Bad\npaths:\n  /v1/x: [\n");

        [$exitCode, $output] = $this->runLint($path);

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('malformed YAML', $output);
    }

    public function test_lint_fails_when_an_operation_lacks_responses(): void
    {
        $path = $this->fixture('missing-responses.yaml', <<<'YAML'
        openapi: 3.1.0
        info:
          title: Bad
          version: 1.0.0
        servers: []
        paths:
          /v1/x:
            get:
              operationId: x
              tags: [System]
        components:
          schemas: {}
        YAML);

        [$exitCode, $output] = $this->runLint($path);

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('operation is missing responses', $output);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runLint(string $specPath): array
    {
        $node = $this->nodeBinary();
        $command = sprintf(
            '%s %s %s 2>&1',
            escapeshellarg($node),
            escapeshellarg(base_path('../deploy/scripts/openapi-lint.mjs')),
            escapeshellarg($specPath),
        );

        exec($command, $output, $exitCode);

        return [$exitCode, implode("\n", $output)];
    }

    private function nodeBinary(): string
    {
        $candidates = [
            getenv('NODE_BINARY') ?: '',
            'node',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            exec(escapeshellarg($candidate).' --version 2>&1', $output, $exitCode);

            if ($exitCode === 0) {
                return $candidate;
            }
        }

        $this->markTestSkipped('Node.js is not available to run the OpenAPI lint.');
    }

    private function fixture(string $name, string $contents): string
    {
        $path = $this->directory.'/'.$name;
        file_put_contents($path, str_replace("\n        ", "\n", $contents)."\n");

        return $path;
    }
}
