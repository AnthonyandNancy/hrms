import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { PAGE_REGISTRY } from '@/router/registry'

const workspace = readFileSync(resolve(__dirname, '../../src/views/WorkspaceView.vue'), 'utf8')
const taskList = readFileSync(
  resolve(__dirname, '../../src/modules/platform/self-service/components/MyTasks.vue'),
  'utf8',
)

const registered = new Set(PAGE_REGISTRY.map((page) => page.path))

describe('M01 workspace links', () => {
  it('routes through the internal route constants rather than dead literals', () => {
    expect(workspace).toContain('INTERNAL_ROUTES')
    expect(workspace).not.toMatch(/go\('\/[a-z]/)
  })

  it('links tasks to the surviving workflow case detail route', () => {
    expect(taskList).toContain('/workflow/cases/${caseId}')
    expect(taskList).not.toContain('`/cases/${caseId}`')
  })

  it('keeps every internal route constant resolvable', () => {
    const constants = readFileSync(
      resolve(__dirname, '../../src/shared/navigation/internalRoutes.ts'),
      'utf8',
    )
    const values = [...constants.matchAll(/: '(\/[^']+)'/g)].map((match) => match[1] ?? '').filter((value) => value !== '')
    expect(values.length).toBeGreaterThan(8)
    for (const path of values) expect(registered.has(path), path).toBe(true)
  })
})
