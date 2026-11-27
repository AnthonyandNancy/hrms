import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const source = readFileSync(resolve(__dirname, '../../src/views/WorkspaceView.vue'), 'utf8')

describe('M01 workspace copy', () => {
  it('localizes contract type/status instead of rendering enum tokens', () => {
    expect(source).toContain("translate('people', 'employmentType'")
    expect(source).not.toMatch(/\{\{\s*data\.contract\.type\s*\}\}/)
  })

  it('explains the no-employment boundary in business language', () => {
    expect(source).toContain('当前账号未绑定员工')
    expect(source).toContain('你仍可处理管理待办')
  })

  it('never exposes API paths or raw enum tokens in the workspace template', () => {
    expect(source).not.toMatch(/GET \/v1|POST \/v1|PATCH \/v1|DELETE \/v1/)
    expect(source).not.toMatch(/\b(FULL_TIME|PART_TIME|ACTIVE|INACTIVE|PROBATION)\b/)
  })
})
