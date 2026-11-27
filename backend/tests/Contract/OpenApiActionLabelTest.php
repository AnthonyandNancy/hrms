<?php

declare(strict_types=1);

namespace Tests\Contract;

use App\Modules\IAM\Authorization\PermissionResourceCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class OpenApiActionLabelTest extends TestCase
{
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $operations = null;

    public function test_every_capability_guarded_write_has_a_unique_chinese_action_label(): void
    {
        $labels = [];
        $violations = [];

        foreach ($this->operations() as $operationKey => $operation) {
            [$method] = explode(' ', $operationKey, 2);

            if (! in_array($method, self::WRITE_METHODS, true) || ! isset($operation['x-capability'])) {
                continue;
            }

            $label = $operation['x-action-label'] ?? null;

            if (! is_string($label) || trim($label) === '') {
                $violations[] = "{$operationKey} has x-capability but no non-empty x-action-label";

                continue;
            }

            if (preg_match('/\p{Han}/u', $label) !== 1) {
                $violations[] = "{$operationKey} x-action-label {$label} contains no Chinese character";
            }

            if (isset($labels[$label])) {
                $violations[] = "{$operationKey} reuses x-action-label {$label} from {$labels[$label]}";
            }

            $labels[$label] = $operationKey;
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }

    public function test_every_page_write_api_capability_is_declared_in_page_actions(): void
    {
        $operations = $this->operations();
        $violations = [];

        foreach ((new PermissionResourceCatalog)->pages() as $page) {
            if ($page['required_capability'] === 'public') {
                continue;
            }

            foreach ($page['required_apis'] as $api) {
                [$method] = explode(' ', $api, 2);

                if (! in_array($method, self::WRITE_METHODS, true)) {
                    continue;
                }

                $operation = $operations[$api] ?? null;
                $capability = is_array($operation) ? ($operation['x-capability'] ?? null) : null;

                if (! is_string($capability) || $capability === '') {
                    $violations[] = "{$page['page_code']} write API {$api} has no OpenAPI x-capability";

                    continue;
                }

                if (! in_array($capability, $page['actions'], true)) {
                    $violations[] = "{$page['page_code']} write API {$api} requires {$capability}, which is absent from page actions";
                }
            }
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }

    public function test_public_allowlisted_writes_are_not_labelled_as_capability_actions(): void
    {
        $operations = $this->operations();

        foreach (['POST /v1/auth/login', 'POST /v1/auth/mfa/challenge'] as $publicWrite) {
            $this->assertArrayHasKey($publicWrite, $operations, "Missing public write {$publicWrite}");
            $this->assertArrayNotHasKey('x-capability', $operations[$publicWrite], "{$publicWrite} must stay outside the capability catalog");
            $this->assertArrayNotHasKey('x-action-label', $operations[$publicWrite], "{$publicWrite} must not be presented as a capability action");
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function operations(): array
    {
        if ($this->operations !== null) {
            return $this->operations;
        }

        $path = realpath(__DIR__.'/../../../docs/openapi/openapi.yaml');
        $this->assertNotFalse($path, 'OpenAPI spec is missing at docs/openapi/openapi.yaml');

        $spec = Yaml::parseFile($path);
        $operations = [];

        foreach ($spec['paths'] ?? [] as $apiPath => $pathItem) {
            if (! is_array($pathItem)) {
                continue;
            }

            foreach ($pathItem as $method => $operation) {
                if (! in_array(strtoupper((string) $method), ['GET', ...self::WRITE_METHODS], true) || ! is_array($operation)) {
                    continue;
                }

                $operations[strtoupper((string) $method).' '.$apiPath] = $operation;
            }
        }

        return $this->operations = $operations;
    }
}
