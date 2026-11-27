import assert from 'node:assert/strict'
import { test } from 'node:test'
import { auditPageModel } from '../audit_page_model.mjs'

test('rejects cross-module tabs and action-like pages', () => {
  const result = auditPageModel({
    pages: [
      { name: 'employees', path: '/hr/employees', kind: 'workspace' },
      { name: 'create', path: '/hr/employees/create', kind: 'workspace' },
    ],
    tabs: [{ page: 'employees', labels: ['员工档案', '部门管理', '岗位管理'] }],
  })
  assert.equal(result.ok, false)
  assert.match(result.failures.join('\n'), /action-like path/)
  assert.match(result.failures.join('\n'), /cross-module tab 部门管理/)
})
