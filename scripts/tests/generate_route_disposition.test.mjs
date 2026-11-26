import assert from 'node:assert/strict'
import { test } from 'node:test'
import { buildDispositionRows, parseRegistryPages } from '../generate_route_disposition.mjs'

test('every registry page has one disposition and a target', () => {
  const rows = buildDispositionRows({
    registry: [
      { name: 'hr-employees', path: '/hr/employees', layer: 'business', capability: 'people.employee.read' },
      { name: 'hr-employees-create', path: '/hr/employees/create', layer: 'business', capability: 'people.employee.read' },
    ],
    decisions: {
      'hr-employees': { disposition: 'keep', targetPageCode: 'employees', owner: 'People', evidence: 'list workspace' },
      'hr-employees-create': { disposition: 'action', targetPageCode: 'employees', owner: 'People', evidence: 'create dialog' },
    },
  })

  assert.equal(rows.length, 2)
  assert.deepEqual(rows.map((row) => row.disposition), ['keep', 'action'])
  assert.equal(rows[1].targetPageCode, 'employees')
})

test('missing decision fails closed', () => {
  assert.throws(
    () => buildDispositionRows({
      registry: [{ name: 'orphan', path: '/orphan', layer: 'business', capability: 'x.y' }],
      decisions: {},
    }),
    /missing disposition/,
  )
})

test('missing target, owner or evidence fails closed', () => {
  const registry = [{ name: 'orphan', path: '/orphan', layer: 'business', capability: 'x.y' }]

  assert.throws(
    () => buildDispositionRows({
      registry,
      decisions: { orphan: { disposition: 'keep', targetPageCode: '', owner: 'People', evidence: 'workspace' } },
    }),
    /missing targetPageCode/,
  )
  assert.throws(
    () => buildDispositionRows({
      registry,
      decisions: { orphan: { disposition: 'keep', targetPageCode: 'orphan', owner: '  ', evidence: 'workspace' } },
    }),
    /missing owner/,
  )
  assert.throws(
    () => buildDispositionRows({
      registry,
      decisions: { orphan: { disposition: 'keep', targetPageCode: 'orphan', owner: 'People', evidence: ' ' } },
    }),
    /missing evidence/,
  )
})

test('invalid disposition fails closed', () => {
  assert.throws(
    () => buildDispositionRows({
      registry: [{ name: 'orphan', path: '/orphan', layer: 'business', capability: 'x.y' }],
      decisions: { orphan: { disposition: 'remove', targetPageCode: 'orphan', owner: 'People', evidence: 'workspace' } },
    }),
    /invalid disposition/,
  )
})

test('deprecate requires evidence quoting written user approval', () => {
  const registry = [{ name: 'orphan', path: '/orphan', layer: 'business', capability: 'x.y' }]

  assert.throws(
    () => buildDispositionRows({
      registry,
      decisions: { orphan: { disposition: 'deprecate', targetPageCode: 'orphan', owner: 'People', evidence: 'engineering decision' } },
    }),
    /requires written user approval/,
  )

  const rows = buildDispositionRows({
    registry,
    decisions: {
      orphan: {
        disposition: 'deprecate',
        targetPageCode: 'orphan',
        owner: 'People',
        evidence: '用户批准（ADR-0099）：“删除 orphan 页面。”',
      },
    },
  })
  assert.equal(rows[0].disposition, 'deprecate')

  for (const negated of [
    '用户未批准（ADR-0099）：“删除 orphan 页面。”',
    '用户不同意（ADR-0099）：“删除 orphan 页面。”',
    'User has not approved (ADR-0099): "delete orphan"',
  ]) {
    assert.throws(
      () => buildDispositionRows({
        registry,
        decisions: { orphan: { disposition: 'deprecate', targetPageCode: 'orphan', owner: 'People', evidence: negated } },
      }),
      /requires written user approval/,
    )
  }
})

test('registry parser handles compact and multiline page objects', () => {
  const source = `export const PAGE_REGISTRY: PageSpec[] = [
  {path: '/compact', name: 'compact', componentKey: 'a', capability: 'compact.read', menu: null, layer: 'business', wave: 'Wave 2', requiredApis: [], actions: [], states: [...REQUIRED_STATES], e2e: [], negativeTests: [...REQUIRED_NEGATIVE_TESTS],},
  {
    path: '/multi',
    name: 'multi',
    componentKey: 'b',
    capability: 'multi.read',
    menu: { group: '菜单', label: '多行', order: 2, icon: 'User' },
    layer: 'business',
    requiredApis: ['GET /v1/multi'],
    actions: [
      { key: 'multi.create', label: '新增', capability: 'multi.manage', api: 'POST /v1/multi', ui: 'primary' },
    ],
    e2e: ['multi'],
  },
]
`

  assert.deepEqual(
    parseRegistryPages(source).map(({ name, path, layer, capability }) => ({ name, path, layer, capability })),
    [
      { name: 'compact', path: '/compact', layer: 'business', capability: 'compact.read' },
      { name: 'multi', path: '/multi', layer: 'business', capability: 'multi.read' },
    ],
  )
})
