#!/usr/bin/env node
/**
 * Dependency-light OpenAPI gate.
 *
 * Parses docs/openapi/openapi.yaml with the pinned `yaml` package and checks
 * the structural invariants this repository actually relies on:
 *   - OpenAPI 3.1 root with info, servers, paths and components;
 *   - every path declares an operation with `responses`;
 *   - every $ref resolves to an existing component;
 *   - health/meta success and 503 responses exist.
 *
 * It fails on malformed YAML (including duplicate mapping keys) and on any
 * operation missing responses.
 */
import { readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseDocument } from 'yaml';

const scriptDir = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(scriptDir, '..', '..');
const specPath = process.argv[2] ? resolve(process.argv[2]) : join(repoRoot, 'docs', 'openapi', 'openapi.yaml');

function fail(message) {
  console.error(`openapi-lint: FAIL: ${message}`);
  process.exit(1);
}

let source;
try {
  source = readFileSync(specPath, 'utf8');
} catch (error) {
  fail(`cannot read ${specPath}: ${error.message}`);
}

const document = parseDocument(source, {
  prettyErrors: true,
  strict: true,
  uniqueKeys: true,
});

if (document.errors.length > 0) {
  fail(`malformed YAML:\n${document.errors.map((error) => error.message).join('\n')}`);
}

const spec = document.toJS();

if (spec === null || typeof spec !== 'object' || Array.isArray(spec)) {
  fail('document root must be a mapping');
}

if (typeof spec.openapi !== 'string' || !/^3\.1\.\d+$/.test(spec.openapi)) {
  fail(`expected OpenAPI 3.1.x, got ${JSON.stringify(spec.openapi)}`);
}

for (const key of ['info', 'servers', 'paths', 'components']) {
  if (spec[key] === undefined) {
    fail(`missing root key "${key}"`);
  }
}

if (typeof spec.info?.title !== 'string' || spec.info.title.trim() === '') {
  fail('info.title must be a non-empty string');
}

if (typeof spec.info?.version !== 'string' || spec.info.version.trim() === '') {
  fail('info.version must be a non-empty string');
}

if (spec.paths === null || typeof spec.paths !== 'object' || Array.isArray(spec.paths)) {
  fail('paths must be a mapping');
}

if (Object.keys(spec.paths).length === 0) {
  fail('paths must not be empty');
}

const httpMethods = new Set(['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace']);
let operationCount = 0;

function walkRefs(value, trail) {
  if (Array.isArray(value)) {
    value.forEach((item, index) => walkRefs(item, `${trail}[${index}]`));
    return;
  }

  if (value === null || typeof value !== 'object') {
    return;
  }

  for (const [key, child] of Object.entries(value)) {
    if (key === '$ref' && typeof child === 'string') {
      if (!child.startsWith('#/')) {
        fail(`external $ref is not supported at ${trail}: ${child}`);
      }

      const segments = child.slice(2).split('/').map((segment) => segment.replaceAll('~1', '/').replaceAll('~0', '~'));
      let target = spec;
      for (const segment of segments) {
        if (target === null || typeof target !== 'object' || !(segment in target)) {
          fail(`unresolved $ref at ${trail}: ${child}`);
        }
        target = target[segment];
      }
    } else {
      walkRefs(child, `${trail}.${key}`);
    }
  }
}

for (const [path, item] of Object.entries(spec.paths)) {
  if (!path.startsWith('/')) {
    fail(`path must start with "/": ${path}`);
  }

  if (item === null || typeof item !== 'object' || Array.isArray(item)) {
    fail(`path item must be a mapping: ${path}`);
  }

  const operations = Object.entries(item).filter(([key]) => httpMethods.has(key));

  if (operations.length === 0) {
    fail(`path has no HTTP operation: ${path}`);
  }

  for (const [method, operation] of operations) {
    operationCount += 1;

    if (operation === null || typeof operation !== 'object' || Array.isArray(operation)) {
      fail(`operation must be a mapping: ${method.toUpperCase()} ${path}`);
    }

    if (operation.responses === undefined || operation.responses === null || typeof operation.responses !== 'object') {
      fail(`operation is missing responses: ${method.toUpperCase()} ${path}`);
    }

    if (Object.keys(operation.responses).length === 0) {
      fail(`operation has empty responses: ${method.toUpperCase()} ${path}`);
    }

    for (const status of Object.keys(operation.responses)) {
      if (!/^([1-5]\d\d|[1-5]XX|default)$/i.test(status)) {
        fail(`invalid response status "${status}": ${method.toUpperCase()} ${path}`);
      }
    }

    if (typeof operation.operationId !== 'string' || operation.operationId.trim() === '') {
      fail(`operation missing operationId: ${method.toUpperCase()} ${path}`);
    }

    if (!Array.isArray(operation.tags) || operation.tags.length === 0) {
      fail(`operation missing tags: ${method.toUpperCase()} ${path}`);
    }
  }
}

walkRefs(spec, '#');

for (const schema of ['Envelope', 'ErrorEnvelope', 'HealthData', 'MetaData', 'Pagination']) {
  if (!spec.components.schemas?.[schema]) {
    fail(`missing components.schemas.${schema}`);
  }
}

for (const scheme of ['sessionCookie', 'csrfHeader']) {
  if (!spec.components.securitySchemes?.[scheme]) {
    fail(`missing components.securitySchemes.${scheme}`);
  }
}

if (!spec.paths['/v1/health']?.get?.responses?.['200']) {
  fail('missing /v1/health 200 response');
}

if (!spec.paths['/v1/health']?.get?.responses?.['503']) {
  fail('missing /v1/health 503 response');
}

if (!spec.paths['/v1/meta']?.get?.responses?.['200']) {
  fail('missing /v1/meta 200 response');
}

console.log(`openapi-lint: OK (${Object.keys(spec.paths).length} paths, ${operationCount} operations, ${spec.openapi})`);
