<?php

declare(strict_types=1);

namespace Tests\Contract;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Keeps the HTTP surface and docs/openapi/openapi.yaml in lockstep.
 *
 * Routes under `storage/` are excluded explicitly; framework probes (`up`)
 * are outside `/v1` and therefore not part of the documented API surface.
 * The test is dependency-free: it reads the small set of fields it needs and
 * validates structure rather than pretending to be a full OpenAPI compiler.
 */
class OpenApiContractTest extends TestCase
{
    private const SPEC_PATH = __DIR__.'/../../../docs/openapi/openapi.yaml';

    /**
     * @var list<string>
     */
    private const INTERNAL_PATH_PREFIXES = [
        'storage/',
    ];

    public function test_every_v1_route_is_documented_in_openapi(): void
    {
        $documented = $this->documentedPaths();
        $missing = [];

        $registered = Route::getRoutes();

        if (! $registered instanceof \Illuminate\Routing\RouteCollection) {
            $registered = new \Illuminate\Routing\RouteCollection;
        }

        foreach ($registered->getRoutes() as $route) {
            $uri = '/'.trim($route->uri(), '/');

            if (! str_starts_with($uri, '/v1/')) {
                continue;
            }

            if ($this->isInternal($uri)) {
                continue;
            }

            if (! in_array($uri, $documented, true)) {
                $missing[] = $uri;
            }
        }

        sort($missing);

        $this->assertSame([], $missing, 'Register these routes in docs/openapi/openapi.yaml: '.implode(', ', $missing));
    }

    public function test_health_and_meta_document_success_and_error_shapes(): void
    {
        $spec = $this->parseSpec();

        $health = $this->path($spec, '/v1/health');
        $meta = $this->path($spec, '/v1/meta');

        $healthResponses = $health['get']['responses'] ?? [];
        $metaResponses = $meta['get']['responses'] ?? [];

        $this->assertArrayHasKey('200', $healthResponses);
        $this->assertArrayHasKey('503', $healthResponses);
        $this->assertArrayHasKey('200', $metaResponses);

        $schemas = $spec['components']['schemas'];

        foreach (['Envelope', 'ErrorEnvelope', 'HealthData', 'MetaData', 'Pagination'] as $schema) {
            $this->assertArrayHasKey($schema, $schemas);
        }

        $this->assertSame(
            ['status', 'version', 'checks', 'time'],
            $schemas['HealthData']['required'],
        );
        $this->assertSame(
            ['version', 'environment', 'server_time', 'supported_timezones', 'api_version'],
            $schemas['MetaData']['required'],
        );
        $this->assertSame(
            ['items', 'page', 'page_size', 'total'],
            $schemas['Pagination']['required'],
        );
    }

    public function test_openapi_is_31_and_declares_auth_schemes(): void
    {
        $spec = $this->parseSpec();

        $this->assertStringStartsWith('3.1', $spec['openapi']);
        $this->assertSame('apiKey', $spec['components']['securitySchemes']['sessionCookie']['type']);
        $this->assertSame('cookie', $spec['components']['securitySchemes']['sessionCookie']['in']);
        $this->assertSame('hrms_session', $spec['components']['securitySchemes']['sessionCookie']['name']);
        $this->assertSame('apiKey', $spec['components']['securitySchemes']['csrfHeader']['type']);
        $this->assertSame('header', $spec['components']['securitySchemes']['csrfHeader']['in']);
        $this->assertSame('X-CSRF-Token', $spec['components']['securitySchemes']['csrfHeader']['name']);
    }

    /**
     * @return list<string>
     */
    private function documentedPaths(): array
    {
        return array_keys($this->parseSpec()['paths']);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseSpec(): array
    {
        $path = realpath(self::SPEC_PATH);
        $this->assertNotFalse($path, 'OpenAPI spec is missing at docs/openapi/openapi.yaml');

        $contents = (string) file_get_contents($path);
        $spec = $this->readYaml($contents);

        $this->assertArrayHasKey('paths', $spec);
        $this->assertIsArray($spec['paths']);

        return $spec;
    }

    /**
     * A deliberately small YAML reader for the subset used by this spec:
     * mappings, sequences, flow sequences, quoted/unquoted scalars, `|` and
     * `>` block scalars. It fails loudly on duplicate keys so malformed
     * documents cannot silently pass.
     *
     * @return array<string, mixed>
     */
    private function readYaml(string $contents): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $contents) ?: [];
        $index = 0;

        return $this->parseBlock($lines, $index, $this->indent($lines[0] ?? ''));
    }

    /**
     * @param  list<string>  $lines
     * @return array<array-key, mixed>
     */
    private function parseBlock(array $lines, int &$index, int $indent): array
    {
        $result = [];
        $isList = false;

        while ($index < count($lines)) {
            $line = rtrim($lines[$index]);

            if ($line === '' || str_starts_with(ltrim($line), '#')) {
                $index++;

                continue;
            }

            $lineIndent = $this->indent($line);

            if ($lineIndent < $indent) {
                break;
            }

            if ($lineIndent !== $indent) {
                $this->fail('Unexpected indentation at line '.($index + 1));
            }

            $trimmed = substr($line, $indent);

            if (str_starts_with($trimmed, '- ')) {
                $isList = true;
                $result[] = $this->parseListItem($trimmed, $lines, $index, $indent);

                continue;
            }

            if (! str_contains($trimmed, ':')) {
                $this->fail('Expected a mapping key at line '.($index + 1));
            }

            [$key, $value] = explode(':', $trimmed, 2);
            $key = trim($key);

            if (strlen($key) >= 2 && str_starts_with($key, "'") && str_ends_with($key, "'")) {
                $key = str_replace("''", "'", substr($key, 1, -1));
            } elseif (strlen($key) >= 2 && str_starts_with($key, '"') && str_ends_with($key, '"')) {
                $key = stripcslashes(substr($key, 1, -1));
            }

            $value = trim($value);

            if ($key === '') {
                $this->fail('Empty mapping key at line '.($index + 1));
            }

            if (array_key_exists($key, $result)) {
                $this->fail("Duplicate YAML key '{$key}' at line ".($index + 1));
            }

            $index++;

            if (in_array($value, ['|', '>'], true)) {
                $result[$key] = $this->parseBlockScalar($lines, $index, $indent, $value);

                continue;
            }

            if ($value !== '') {
                $result[$key] = $this->parseScalar($value);

                continue;
            }

            while ($index < count($lines)) {
                $next = $lines[$index];
                $nextTrimmed = ltrim($next);

                if ($next === '' || str_starts_with($nextTrimmed, '#')) {
                    $index++;

                    continue;
                }

                $nextIndent = $this->indent($next);

                if ($nextIndent <= $indent) {
                    $result[$key] = [];

                    continue 2;
                }

                $result[$key] = $this->parseBlock($lines, $index, $nextIndent);

                continue 2;
            }

            $result[$key] = [];
        }

        if ($isList && $result !== []) {
            $this->assertTrue(array_is_list($result), 'Mixed list/mapping YAML block.');
        }

        return $result;
    }

    /**
     * @param  list<string>  $lines
     */
    private function parseListItem(string $trimmed, array $lines, int &$index, int $indent): mixed
    {
        $value = trim(substr($trimmed, 2));

        if ($value === '') {
            $index++;
            $nextIndent = $this->indent($lines[$index] ?? '');
            $this->assertGreaterThan($indent, $nextIndent, 'List item without nested value.');

            return $this->parseBlock($lines, $index, $nextIndent);
        }

        if (str_contains($value, ':')) {
            [$key, $rest] = explode(':', $value, 2);
            $index++;
            $nested = [$key => $rest === '' ? [] : $this->parseScalar(trim($rest))];

            $next = $lines[$index] ?? '';
            $nextTrimmed = ltrim($next);

            if ($next !== '' && ! str_starts_with($nextTrimmed, '#') && $this->indent($next) > $indent) {
                $child = $this->parseBlock($lines, $index, $this->indent($next));
                $nested = array_merge($nested, $child);
            }

            return $nested;
        }

        $index++;

        return $this->parseScalar($value);
    }

    /**
     * @param  list<string>  $lines
     */
    private function parseBlockScalar(array $lines, int &$index, int $parentIndent, string $style): string
    {
        $parts = [];

        while ($index < count($lines)) {
            $line = $lines[$index];

            if (trim($line) === '') {
                $parts[] = '';
                $index++;

                continue;
            }

            if ($this->indent($line) <= $parentIndent) {
                break;
            }

            $parts[] = substr($line, $parentIndent + 2);
            $index++;
        }

        return $style === '|' ? implode("\n", $parts) : preg_replace('/\s+/', ' ', implode(' ', $parts)) ?? '';
    }

    private function parseScalar(string $value): mixed
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, '#')) {
            return '';
        }

        if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
            $inner = trim(substr($value, 1, -1));

            return $inner === '' ? [] : array_map(fn (string $part): mixed => $this->parseScalar($part), explode(',', $inner));
        }

        if (str_starts_with($value, "'") && str_ends_with($value, "'")) {
            return str_replace("''", "'", substr($value, 1, -1));
        }

        if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
            return stripcslashes(substr($value, 1, -1));
        }

        if ($value === 'true') {
            return true;
        }

        if ($value === 'false') {
            return false;
        }

        if ($value === 'null' || $value === '~') {
            return null;
        }

        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function path(array $spec, string $path): array
    {
        $this->assertArrayHasKey($path, $spec['paths']);

        return $spec['paths'][$path];
    }

    private function isInternal(string $uri): bool
    {
        foreach (self::INTERNAL_PATH_PREFIXES as $prefix) {
            if (str_starts_with(ltrim($uri, '/'), $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function indent(string $line): int
    {
        return strlen($line) - strlen(ltrim($line));
    }
}
