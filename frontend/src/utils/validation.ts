import { z } from 'zod'

export const loginSchema = z.object({
  email: z.string().trim().min(1, '请输入邮箱').email('请输入有效的邮箱地址'),
  password: z.string().min(8, '密码至少 8 位'),
})

export const totpSchema = z.object({
  code: z.string().regex(/^\d{6}$/, '请输入 6 位动态验证码'),
})

const datePattern = /^\d{4}-\d{2}-\d{2}$/

export const leaveRequestSchema = z
  .object({
    leaveTypeCode: z.string().min(1, '请选择假期类型'),
    startDate: z.string().regex(datePattern, '请选择开始日期'),
    endDate: z.string().regex(datePattern, '请选择结束日期'),
    halfDay: z.boolean(),
    reason: z.string().trim().min(5, '请假事由至少 5 个字').max(200, '请假事由不能超过 200 个字'),
  })
  .superRefine((value, ctx) => {
    if (value.endDate < value.startDate) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['endDate'], message: '结束日期不能早于开始日期' })
    }
    if (value.halfDay && value.startDate !== value.endDate) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['endDate'], message: '半天假必须为同一天' })
    }
  })

export const decisionSchema = z
  .object({
    action: z.enum(['APPROVE', 'REJECT', 'RETURN']),
    comment: z.string().trim().max(500, '审批意见不能超过 500 个字'),
  })
  .superRefine((value, ctx) => {
    if ((value.action === 'REJECT' || value.action === 'RETURN') && value.comment.length < 2) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['comment'],
        message: '驳回或退回时必须填写至少 2 个字的说明',
      })
    }
  })

export const correctionSchema = z
  .object({
    date: z.string().regex(datePattern, '请选择考勤日期'),
    correctionType: z.enum(['MISSING_PUNCH', 'WRONG_TIME', 'WRONG_STATUS']),
    firstIn: z.string().regex(/^([01]\d|2[0-3]):[0-5]\d$/, '请输入有效的上班时间'),
    lastOut: z.string().regex(/^([01]\d|2[0-3]):[0-5]\d$/, '请输入有效的下班时间'),
    reason: z.string().trim().min(5, '补正原因至少 5 个字').max(200, '补正原因不能超过 200 个字'),
  })
  .refine((value) => value.lastOut > value.firstIn, {
    path: ['lastOut'],
    message: '下班时间必须晚于上班时间',
  })

export const exportSchema = z.object({
  purpose: z.enum(['BUSINESS_OPERATION', 'AUDIT', 'COMPLIANCE', 'PAYROLL_RECONCILIATION'], {
    errorMap: () => ({ message: '请选择导出目的' }),
  }),
  fields: z.array(z.string()).min(1, '至少选择一个可导出字段'),
  acknowledged: z.literal(true, {
    errorMap: () => ({ message: '请确认导出数据将带有水印并记录审计日志' }),
  }),
})

export const publishNoticeSchema = z.object({
  title: z.string().trim().min(4, '公告标题至少 4 个字').max(80, '公告标题不能超过 80 个字'),
  body: z.string().trim().min(10, '公告正文至少 10 个字').max(3000, '公告正文不能超过 3000 个字'),
  audience: z.enum(['ALL', 'ORG', 'ROLE']),
  audienceLabel: z.string().trim().min(1, '请选择或填写发布范围'),
  publishAt: z.string().min(1, '请选择发布时间'),
  requiresAck: z.boolean(),
})

export const orgMoveSchema = z.object({
  nodeId: z.number().int().positive(),
  targetId: z.number().int().positive(),
  effectiveDate: z.string().regex(datePattern, '请选择生效日期'),
}).refine((value) => value.nodeId !== value.targetId, {
  message: '目标组织不能与当前组织相同',
  path: ['targetId'],
})

export type LoginInput = z.infer<typeof loginSchema>
export type LeaveRequestInput = z.infer<typeof leaveRequestSchema>
export type DecisionInput = z.infer<typeof decisionSchema>
export type CorrectionInput = z.infer<typeof correctionSchema>
export type ExportInput = z.infer<typeof exportSchema>
export type PublishNoticeInput = z.infer<typeof publishNoticeSchema>

export type ValidationIssue = {
  path: string
  message: string
}

export function validate<T>(schema: z.ZodType<T>, value: unknown): {
  success: boolean
  data?: T
  issues: ValidationIssue[]
} {
  const parsed = schema.safeParse(value)
  if (parsed.success) {
    return { success: true, data: parsed.data, issues: [] }
  }
  return {
    success: false,
    issues: parsed.error.issues.map((issue) => ({
      path: issue.path.join('.'),
      message: issue.message,
    })),
  }
}
