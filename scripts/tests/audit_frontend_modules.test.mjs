import assert from 'node:assert/strict'
import { mkdtempSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import test from 'node:test'
import {
  BOUNDARY_ALLOWLIST,
  auditImports,
  scanProject,
} from '../audit_frontend_modules.mjs'

const PAGE_FILE = 'frontend/src/modules/people/pages/EmployeeListPage.vue'
const SHARED_FILE = 'frontend/src/components/DataTable.vue'

test('allows own-module imports and documented shared dependencies from a page', () => {
  const failures = auditImports(
    [
      {
        file: PAGE_FILE,
        imports: [
          '../components/PeoplePageShell.vue',
          '../api/employees',
          '../types',
          '@/modules/people/components/DetailFacts.vue',
          '@/shared/components/FilterBar.vue',
          '@/components/DataTable.vue',
          '@/api/http',
          '@/stores/session',
          '@/router',
          '@/utils/format',
          '@/types/page',
          'vue',
          'vue-router',
          'pinia',
          'element-plus',
          '@element-plus/icons-vue',
        ],
      },
    ],
    [],
  )

  assert.deepEqual(failures, [])
})

test('rejects a cross-module page import with file and import source', () => {
  const failures = auditImports(
    [
      {
        file: PAGE_FILE,
        imports: ['@/modules/platform/pages/SystemUsersPage.vue'],
      },
    ],
    [],
  )

  assert.equal(failures.length, 1)
  assert.match(failures[0], /EmployeeListPage\.vue/)
  assert.match(failures[0], /@\/modules\/platform\/pages\/SystemUsersPage\.vue/)
  assert.match(failures[0], /cross-module page import/)
})

test('rejects global fetch but not a same-named client method', () => {
  const failures = auditImports(
    [
      {
        file: PAGE_FILE,
        source: [
          'export async function load() {',
          "  await client.fetch('/v1/employees')",
          "  await window.fetch('/v1/employees')",
          "  await globalThis.fetch('/v1/employees')",
          '}',
        ].join('\n'),
      },
    ],
    [],
  )

  assert.equal(failures.length, 2)
  assert.equal(failures.filter((failure) => /forbidden fetch call/.test(failure)).length, 2)
  assert.doesNotMatch(failures.join('\n'), /client\.fetch/)
  assert.match(failures.join('\n'), /line 3/)
  assert.match(failures.join('\n'), /line 4/)
})

test('rejects relative cross-module page imports', () => {
  const failures = auditImports(
    [
      {
        file: PAGE_FILE,
        source: "import UsersPage from '../../platform/pages/SystemUsersPage.vue'\n",
      },
    ],
    [],
  )

  assert.equal(failures.length, 1)
  assert.match(failures[0], /cross-module page import/)
})

test('rejects axios imports and direct fetch calls', () => {
  const failures = auditImports(
    [
      {
        file: PAGE_FILE,
        source: [
          "import axios from 'axios'",
          'export async function load() {',
          "  await fetch('/v1/employees')",
          '}',
        ].join('\n'),
      },
    ],
    [],
  )

  assert.equal(failures.length, 2)
  assert.match(failures.join('\n'), /EmployeeListPage\.vue.*axios/)
  assert.match(failures.join('\n'), /EmployeeListPage\.vue.*fetch/)
})

test('rejects the legacy views shell and axios in module components and api files', () => {
  const failures = auditImports(
    [
      {
        file: 'frontend/src/modules/people/components/PeoplePageShell.vue',
        imports: ['@/views/people/PeopleWorkspace.vue'],
      },
      {
        file: 'frontend/src/modules/people/api/employees.ts',
        imports: ['axios'],
      },
    ],
    [],
  )

  assert.equal(failures.length, 1)
  assert.match(failures[0], /components\/PeoplePageShell\.vue/)
  assert.match(failures[0], /@\/views\/people\/PeopleWorkspace\.vue/)
})

test('rejects page identity props declared by a shared component', () => {
  const failures = auditImports(
    [],
    [{ file: SHARED_FILE, props: ['componentKey'] }],
  )

  assert.equal(failures.length, 1)
  assert.match(failures[0], /DataTable\.vue/)
  assert.match(failures[0], /componentKey/)
  assert.match(failures[0], /page identity prop/)
})

test('accepts safe visual-only props in shared components', () => {
  const failures = auditImports(
    [],
    [
      {
        file: SHARED_FILE,
        source: [
          '<script setup lang="ts">',
          'withDefaults(defineProps<{',
          "  variant?: 'compact' | 'full'",
          '  density?: number',
          '}>(), { variant: "compact", density: 1 })',
          '</script>',
        ].join('\n'),
      },
    ],
  )

  assert.deepEqual(failures, [])
})

test('parses withDefaults props and ignores nested or commented identity names', () => {
  const failures = auditImports(
    [],
    [
      {
        file: SHARED_FILE,
        source: `
          <script setup lang="ts">
          withDefaults(defineProps<{
            rows: Array<{ componentKey: string }>
            // shellKey?: string
            /* pageCode: string */
            mode?: 'read' | 'write'
            variant?: string
          }>(), { variant: 'compact' })
          </script>
        `,
      },
    ],
  )

  assert.equal(failures.length, 1)
  assert.match(failures[0], /page identity prop mode/)
  assert.doesNotMatch(failures[0], /componentKey|shellKey|pageCode/)
})

test('does not treat type-only imports, comments or strings as runtime dependencies', () => {
  const failures = auditImports(
    [
      {
        file: PAGE_FILE,
        source: [
          "import type { LegacyPage } from '@/views/LegacyPage.vue'",
          "import type { ApiShape } from 'axios'",
          "// import axios from 'axios'",
          "/* fetch('/v1/employees') */",
          'const example = "import axios from \'axios\'; fetch(\'/v1/employees\')"',
          'const template = `import axios from \'axios\'; fetch(\'/v1/employees\')`',
        ].join('\n'),
      },
    ],
    [],
  )

  assert.deepEqual(failures, [])
})

test('allows the documented ActionGate presentation mode exception only', () => {
  const failures = auditImports(
    [],
    [{ file: 'frontend/src/components/ActionGate.vue', props: ['mode'] }],
  )

  assert.deepEqual(failures, [])
})

test('does not extend the ActionGate mode exception to other components', () => {
  const failures = auditImports(
    [],
    [{ file: 'frontend/src/components/OtherPanel.vue', props: ['mode'] }],
  )

  assert.equal(failures.length, 1)
  assert.match(failures[0], /OtherPanel\.vue: page identity prop mode/)
})

test('keeps the module boundary allowlist narrow and documented', () => {
  assert.equal(BOUNDARY_ALLOWLIST.length, 1)
  assert.deepEqual(
    BOUNDARY_ALLOWLIST.map(({ file, type, prop }) => ({ file, type, prop })),
    [
      {
        file: 'frontend/src/components/ActionGate.vue',
        type: 'prop',
        prop: 'mode',
      },
    ],
  )
  assert.match(BOUNDARY_ALLOWLIST[0].reason, /disabled\/denied presentation/)
  assert.match(BOUNDARY_ALLOWLIST[0].reason, /not a page identity prop/)
})

test('scanProject reports ok with scanned=0 when modules are absent', () => {
  const root = mkdtempSync(join(tmpdir(), 'factoryhr-module-boundary-'))

  try {
    const report = scanProject(root)

    assert.equal(report.ok, true)
    assert.equal(report.scanned, 0)
    assert.equal(report.pagesScanned, 0)
    assert.equal(report.sharedComponentsScanned, 0)
    assert.deepEqual(report.failures, [])
  } finally {
    rmSync(root, { recursive: true, force: true })
  }
})
