<?php

declare(strict_types=1);

namespace Tests\Contract;

use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Executable OpenAPI quality gate for the V1.1 contract.
 *
 * This complements OpenApiContractTest (route coverage) and the Redocly/YAML
 * linters by checking the invariants product code depends on:
 *   - every operation returns a success response with a schema;
 *   - protected operations carry 401/403 and a 5xx response;
 *   - path operations document 404 and body operations document 422;
 *   - conflict/throttle behaviour is documented where it is executable;
 *   - x-capability exactly matches the registered Laravel middleware.
 */
final class OpenApiSchemaContractTest extends TestCase
{
    /**
     * The explicit public allowlist from the V1.1 design.
     *
     * @var list<string>
     */
    private const PUBLIC_PATHS = [
        '/v1/health',
        '/v1/health/live',
        '/v1/health/ready',
        '/v1/meta',
        '/v1/auth/login',
        '/v1/auth/mfa/challenge',
    ];

    /**
     * The report export is intentionally a raw UTF-8 CSV stream, not JSON.
     * It still must document a concrete success schema.
     *
     * @var list<string>
     */
    private const RAW_DOWNLOAD_PATHS = [
        '/v1/reporting/exports/{jobId}/download',
    ];

    /**
     * Paths whose controller reports selector errors as 422 rather than 404.
     *
     * @var list<string>
     */
    private const NO_404_PATHS = [
        '/v1/reporting/dashboard/metrics/{metric}',
    ];

    /**
     * State or idempotency conflicts that are executable in the controller.
     * Every operation already carrying a 409 remains covered automatically;
     * this list adds missing conflict contracts discovered in the code.
     *
     * @var list<string>
     */
    private const CONFLICT_OPERATIONS = [
        'POST /v1/auth/break-glass',
        'POST /v1/recruitment/candidates/{id}/interviews',
        'POST /v1/performance/cycles',
        'POST /v1/performance/reviews',
        'POST /v1/performance/reviews/{id}/appeals',
        'POST /v1/training/programs',
        'POST /v1/training/sessions',
        'POST /v1/training/enrollments',
        'POST /v1/training/enrollments/{id}/complete',
        'POST /v1/engagement/notices/{notice}/acknowledge',
        'PATCH /v1/positions/{id}',
    ];

    /**
     * @var array<string, mixed>
     */
    private array $spec;

    private function repositoryRoot(): string
    {
        return (string) realpath(__DIR__.'/../../..');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $path = realpath($this->repositoryRoot().'/docs/openapi/openapi.yaml');
        $this->assertNotFalse($path, 'OpenAPI spec is missing at docs/openapi/openapi.yaml');
        $this->spec = Yaml::parseFile($path);
    }

    public function test_every_operation_has_a_success_schema(): void
    {
        $violations = [];

        foreach ($this->operations() as $operation) {
            $status = $this->successStatus($operation['operation']['responses']);

            if ($status === null) {
                $violations[] = $operation['label'].' has no 2xx success response';

                continue;
            }

            $schema = $this->successSchema($operation['operation']['responses'][$status] ?? null);

            if ($schema === null) {
                $violations[] = $operation['label'].' has no success response schema';

                continue;
            }

            if (in_array($operation['path'], self::RAW_DOWNLOAD_PATHS, true)) {
                continue;
            }

            if ($this->jsonSuccessSchema($operation['operation']['responses'][$status] ?? null) === null) {
                $violations[] = $operation['label'].' success is not application/json';
            }
        }

        $this->report('success-schema violations', $violations);
        $this->assertSame([], $violations, implode("\n", $violations));
    }

    public function test_every_operation_documents_the_applicable_error_set(): void
    {
        $violations = [];

        foreach ($this->operations() as $operation) {
            $hasFiveHundred = $operation['operation']['responses']['500'] ?? null;
            $hasDefault = $operation['operation']['responses']['default'] ?? null;

            if ($hasFiveHundred === null && $hasDefault === null) {
                $violations[] = $operation['label'].' missing 500/default';
            }

            if (! $this->isPublic($operation['path'])) {
                foreach (['401', '403'] as $status) {
                    if (! array_key_exists($status, $operation['operation']['responses'])) {
                        $violations[] = $operation['label'].' missing '.$status;
                    }
                }
            }

            if (str_contains($operation['path'], '{') && ! in_array($operation['path'], self::NO_404_PATHS, true)) {
                if (! array_key_exists('404', $operation['operation']['responses'])) {
                    $violations[] = $operation['label'].' missing 404';
                }
            }

            if (($operation['operation']['requestBody'] ?? null) !== null && ! array_key_exists('422', $operation['operation']['responses'])) {
                $violations[] = $operation['label'].' missing 422 for request body';
            }

            if (in_array($operation['label'], self::CONFLICT_OPERATIONS, true) && ! array_key_exists('409', $operation['operation']['responses'])) {
                $violations[] = $operation['label'].' missing 409';
            }

            if ($this->isThrottled($operation['path']) && ! array_key_exists('429', $operation['operation']['responses'])) {
                $violations[] = $operation['label'].' missing 429';
            }
        }

        $this->report('error-set violations', $violations);
        $this->assertSame([], $violations, implode("\n", $violations));
    }

    public function test_every_protected_operation_has_x_capability_matching_route_middleware(): void
    {
        $routes = $this->registeredRoutes();
        $violations = [];

        foreach ($this->operations() as $operation) {
            if ($this->isPublic($operation['path'])) {
                continue;
            }

            $route = $routes[$this->routeKey($operation['path'], $operation['method'])] ?? null;

            if ($route === null) {
                $violations[] = $operation['label'].' has no registered route';

                continue;
            }

            $expected = $this->capabilitiesFromMiddleware($route);

            if ($expected === []) {
                $violations[] = $operation['label'].' has no capability middleware';

                continue;
            }

            $documented = $operation['operation']['x-capability'] ?? null;

            if (! is_string($documented) || trim($documented) === '') {
                $violations[] = $operation['label'].' missing x-capability (expected '.implode(',', $expected).')';

                continue;
            }

            $actual = array_values(array_filter(array_map('trim', explode(',', $documented))));

            if ($actual !== $expected) {
                $violations[] = $operation['label'].' x-capability '.implode(',', $actual).' != '.implode(',', $expected);
            }
        }

        $this->report('capability violations', $violations);
        $this->assertSame([], $violations, implode("\n", $violations));
    }

    public function test_every_registered_v1_operation_is_documented_exactly(): void
    {
        $documented = [];

        foreach ($this->operations() as $operation) {
            $documented[$this->routeKey($operation['path'], $operation['method'])] = true;
        }

        $missing = [];

        foreach ($this->registeredRoutes() as $key => $route) {
            if (! str_starts_with($key, 'GET /v1/') && ! str_starts_with($key, 'POST /v1/')
                && ! str_starts_with($key, 'PUT /v1/') && ! str_starts_with($key, 'PATCH /v1/')
                && ! str_starts_with($key, 'DELETE /v1/')) {
                continue;
            }

            if (! isset($documented[$key])) {
                $missing[] = $key;
            }
        }

        sort($missing);
        $this->assertSame([], $missing, 'Undocumented registered operations: '.implode(', ', $missing));
    }

    /**
     * @return list<array{method: string, path: string, label: string, operation: array<string, mixed>}>
     */
    private function operations(): array
    {
        $operations = [];

        foreach ($this->spec['paths'] as $path => $item) {
            foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
                if (! isset($item[$method])) {
                    continue;
                }

                $operations[] = [
                    'method' => strtoupper($method),
                    'path' => $path,
                    'label' => strtoupper($method).' '.$path,
                    'operation' => $item[$method],
                ];
            }
        }

        return $operations;
    }

    /**
     * @param  array<string, mixed>  $responses
     */
    private function successStatus(array $responses): ?string
    {
        foreach (array_keys($responses) as $status) {
            if (preg_match('/^2\d\d$/', (string) $status) === 1) {
                return (string) $status;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $response
     * @return array<string, mixed>|null
     */
    private function successSchema(?array $response): ?array
    {
        $resolved = $this->resolve($response);

        foreach ((array) ($resolved['content'] ?? []) as $mediaType) {
            if (isset($mediaType['schema']) && is_array($mediaType['schema'])) {
                return $mediaType['schema'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $response
     * @return array<string, mixed>|null
     */
    private function jsonSuccessSchema(?array $response): ?array
    {
        $resolved = $this->resolve($response);

        return $resolved['content']['application/json']['schema'] ?? null;
    }

    /**
     * @param  array<string, mixed>|null  $value
     * @return array<string, mixed>
     */
    private function resolve(?array $value): array
    {
        if ($value === null) {
            return [];
        }

        $ref = $value['$ref'] ?? null;

        if (! is_string($ref) || ! str_starts_with($ref, '#/')) {
            return $value;
        }

        $target = $this->spec;

        foreach (explode('/', substr($ref, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);

            if (! is_array($target) || ! array_key_exists($segment, $target)) {
                $this->fail("Unresolved \$ref {$ref}");
            }

            $target = $target[$segment];
        }

        return is_array($target) ? $target : [];
    }

    /**
     * @return array<string, Route>
     */
    private function registeredRoutes(): array
    {
        $registered = app('router')->getRoutes();

        if (! $registered instanceof RouteCollection) {
            return [];
        }

        $routes = [];

        foreach ($registered->getRoutes() as $route) {
            $uri = '/'.trim($route->uri(), '/');

            if (! str_starts_with($uri, '/v1/')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $routes[$this->routeKey($uri, $method)] = $route;
            }
        }

        return $routes;
    }

    private function routeKey(string $path, string $method): string
    {
        $normalized = preg_replace('/\{[^}]+\}/', '{}', $path) ?? $path;

        return strtoupper($method).' '.$normalized;
    }

    /**
     * @return list<string>
     */
    private function capabilitiesFromMiddleware(Route $route): array
    {
        $capabilities = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! str_starts_with($middleware, 'capability:')) {
                continue;
            }

            $value = substr($middleware, strlen('capability:'));
            $parts = explode(',', $value);
            $code = trim((string) array_shift($parts));

            if ($code !== '') {
                $capabilities[] = $code;
            }
        }

        return $capabilities;
    }

    private function isPublic(string $path): bool
    {
        return in_array($path, self::PUBLIC_PATHS, true);
    }

    private function isThrottled(string $path): bool
    {
        return in_array($path, [
            '/v1/auth/login',
            '/v1/auth/mfa/challenge',
            '/v1/reporting/exports',
        ], true);
    }

    /**
     * @param  list<string>  $violations
     */
    private function report(string $label, array $violations): void
    {
        fwrite(STDERR, sprintf(
            "\nOpenApiSchemaContractTest: %d %s across %d operations\n",
            count($violations),
            $label,
            count($this->operations()),
        ));

        foreach ($violations as $violation) {
            fwrite(STDERR, "  - {$violation}\n");
        }
    }
}
