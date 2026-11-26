import assert from 'node:assert/strict'
import test from 'node:test'
import {
  PUBLIC_WRITE_EXCEPTIONS,
  auditActionManifest,
} from '../audit_action_manifest.mjs'

const CATALOG = `
final class CapabilityCatalog
{
    private function build(): array
    {
        return [
            new CapabilityDefinition('people.employee.read', 'employee', ['read'], [], 'P1', 'low', null, 'Read.'),
            new CapabilityDefinition('people.employee.manage', 'employee', ['create'], [], 'P2', 'high', null, 'Manage.'),
        ];
    }
}
`

function registry({ actions = '[]', capability = 'people.employee.read', name = 'fixture-page' } = {}) {
  return `
    export const PAGE_REGISTRY: PageSpec[] = [
      {
        path: '/fixture',
        name: '${name}',
        componentKey: 'fixture/page',
        capability: '${capability}',
        menu: null,
        layer: 'business',
        wave: 'Wave 5',
        requiredApis: ['GET /v1/fixture', 'POST /v1/fixture'],
        actions: ${actions},
        states: [...REQUIRED_STATES],
        e2e: ['fixture'],
        negativeTests: [...REQUIRED_NEGATIVE_TESTS],
      },
    ]
  `
}

function openapi({ xCapability = 'people.employee.manage', path = '/v1/fixture' } = {}) {
  return `
openapi: 3.1.0
info:
  title: Fixture
  version: 1.0.0
paths:
  ${path}:
    post:
      operationId: createFixture
      x-capability: ${xCapability}
      responses:
        "201":
          description: Created
`
}

function audit(overrides = {}) {
  return auditActionManifest({
    registrySource: registry(),
    catalogSource: CATALOG,
    openapiSource: openapi(),
    publicWriteExceptions: [],
    ...overrides,
  })
}

const validAction = `[
  {
    key: 'fixture.create',
    label: '新增',
    capability: 'people.employee.manage',
    api: 'POST /v1/fixture',
    ui: 'primary',
  },
]`

test('passes when a write page has a catalogued action matching OpenAPI', () => {
  const result = audit({ registrySource: registry({ actions: validAction }) })

  assert.equal(result.ok, true, result.failures.join('\n'))
  assert.deepEqual(result.failures, [])
  assert.equal(result.writePageCount, 1)
  assert.equal(result.pagesWithActions, 1)
  assert.equal(result.actionCount, 1)
})

test('fails when a write page has no declared action', () => {
  const result = audit()

  assert.equal(result.ok, false)
  assert.match(result.failures.join('\n'), /fixture-page: write page declares no action/)
})

test('fails when an action capability is not in CapabilityCatalog', () => {
  const result = audit({
    registrySource: registry({
      actions: validAction.replace('people.employee.manage', 'people.employee.invent'),
    }),
  })

  assert.equal(result.ok, false)
  assert.match(result.failures.join('\n'), /unknown capability people\.employee\.invent/)
})

test('fails when OpenAPI x-capability does not equal the action capability', () => {
  const result = audit({
    registrySource: registry({ actions: validAction }),
    openapiSource: openapi({ xCapability: 'people.employee.read' }),
  })

  assert.equal(result.ok, false)
  assert.match(result.failures.join('\n'), /x-capability people\.employee\.read != people\.employee\.manage/)
})

test('fails when an action API is missing from OpenAPI', () => {
  const result = audit({
    registrySource: registry({ actions: validAction }),
    openapiSource: openapi({ path: '/v1/other' }),
  })

  assert.equal(result.ok, false)
  assert.match(result.failures.join('\n'), /POST \/v1\/fixture is not documented in OpenAPI/)
})

test('allows only the explicit public login write exception', () => {
  assert.equal(PUBLIC_WRITE_EXCEPTIONS.length, 1)
  assert.deepEqual(PUBLIC_WRITE_EXCEPTIONS[0].pageCode, 'login')
  assert.deepEqual(PUBLIC_WRITE_EXCEPTIONS[0].api, 'POST /v1/auth/login')

  const loginRegistry = `
    export const PAGE_REGISTRY: PageSpec[] = [
      {
        path: '/login',
        name: 'login',
        capability: 'public',
        requiredApis: ['POST /v1/auth/login'],
        actions: [],
      },
    ]
  `
  const loginOpenApi = `
openapi: 3.1.0
paths:
  /v1/auth/login:
    post:
      operationId: login
      security: []
      responses:
        "200":
          description: Session issued
`
  const login = audit({
    registrySource: loginRegistry,
    openapiSource: loginOpenApi,
    publicWriteExceptions: PUBLIC_WRITE_EXCEPTIONS,
  })
  assert.equal(login.ok, true, login.failures.join('\n'))
  assert.deepEqual(login.publicWriteExceptionsUsed, ['login: POST /v1/auth/login'])

  const otherPublicPage = loginRegistry.replace("name: 'login'", "name: 'other-public'")
  const rejected = audit({
    registrySource: otherPublicPage,
    openapiSource: loginOpenApi,
    publicWriteExceptions: PUBLIC_WRITE_EXCEPTIONS,
  })
  assert.equal(rejected.ok, false)
  assert.match(rejected.failures.join('\n'), /other-public: write page declares no action/)
})

test('fails loudly when the registry or capability catalog cannot be parsed', () => {
  const noPages = audit({ registrySource: 'export const PAGE_REGISTRY: PageSpec[] = []' })
  assert.equal(noPages.ok, false)
  assert.match(noPages.failures.join('\n'), /parse error: no registry pages found/)

  const noCapabilities = audit({ catalogSource: 'final class CapabilityCatalog {}' })
  assert.equal(noCapabilities.ok, false)
  assert.match(noCapabilities.failures.join('\n'), /parse error: no CapabilityDefinition codes found/)
})
