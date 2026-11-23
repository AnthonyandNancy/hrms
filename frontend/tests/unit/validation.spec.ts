import { describe, expect, it } from 'vitest'
import {
  correctionSchema,
  decisionSchema,
  exportSchema,
  leaveRequestSchema,
  loginSchema,
  publishNoticeSchema,
  validate,
} from '@/utils/validation'

describe('form validation', () => {
  it('validates the login identifier and minimum password length', () => {
    expect(validate(loginSchema, { username: 'demo.hr', password: 'Demo!2026' }).success).toBe(true)
    expect(validate(loginSchema, { username: 'hr@demo.example', password: 'Demo!2026' }).success).toBe(true)
    const invalid = validate(loginSchema, { username: '   ', password: 'short' })
    expect(invalid.success).toBe(false)
    expect(invalid.issues.map((issue) => issue.path)).toEqual(['username', 'password'])
  })

  it('rejects reversed and half-day leave ranges', () => {
    const reversed = validate(leaveRequestSchema, {
      leaveTypeCode: 'ANNUAL',
      startDate: '2026-11-28',
      endDate: '2026-11-27',
      halfDay: false,
      reason: '家庭事务需要请假',
    })
    expect(reversed.success).toBe(false)
    expect(reversed.issues.some((issue) => issue.path === 'endDate')).toBe(true)

    const halfDay = validate(leaveRequestSchema, {
      leaveTypeCode: 'ANNUAL',
      startDate: '2026-11-27',
      endDate: '2026-11-28',
      halfDay: true,
      reason: '下午处理家庭事务',
    })
    expect(halfDay.success).toBe(false)
  })

  it('requires a meaningful comment for reject and return decisions', () => {
    expect(validate(decisionSchema, { action: 'APPROVE', comment: '' }).success).toBe(true)
    const reject = validate(decisionSchema, { action: 'REJECT', comment: '不' })
    expect(reject.success).toBe(false)
    expect(reject.issues[0]?.path).toBe('comment')
    expect(validate(decisionSchema, { action: 'RETURN', comment: '请补充证明' }).success).toBe(true)
  })

  it('validates attendance correction time order and reason', () => {
    const base = {
      date: '2026-11-23',
      correctionType: 'MISSING_PUNCH' as const,
      firstIn: '09:00',
      lastOut: '18:30',
      reason: '忘记下班打卡',
    }
    expect(validate(correctionSchema, base).success).toBe(true)
    const invalid = validate(correctionSchema, { ...base, lastOut: '08:30' })
    expect(invalid.success).toBe(false)
    expect(invalid.issues.some((issue) => issue.path === 'lastOut')).toBe(true)
  })

  it('requires export purpose, fields and acknowledgement', () => {
    const invalid = validate(exportSchema, { purpose: '', fields: [], acknowledged: false })
    expect(invalid.success).toBe(false)
    const valid = validate(exportSchema, {
      purpose: 'AUDIT',
      fields: ['org_path', 'headcount'],
      acknowledged: true,
    })
    expect(valid.success).toBe(true)
  })

  it('requires a real audience and body for notices', () => {
    const invalid = validate(publishNoticeSchema, {
      title: '短',
      body: '太短',
      audience: 'ALL',
      audienceLabel: '',
      publishAt: '',
      requiresAck: true,
    })
    expect(invalid.success).toBe(false)
    expect(invalid.issues.length).toBeGreaterThanOrEqual(3)
  })
})
