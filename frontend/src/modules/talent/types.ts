import type { Component } from 'vue'

export type TalentDomain = 'performance' | 'recruitment-requests' | 'recruitment-candidates' | 'training'

export type TalentFormKind =
  | 'cycle-create'
  | 'template-create'
  | 'review-create'
  | 'review-submit'
  | 'review-appeal'
  | 'indicator-create'
  | 'goal-create'
  | 'feedback-create'
  | 'calibration-create'
  | 'cycle-publish'
  | 'requisition-create'
  | 'opening-publish'
  | 'candidate-create'
  | 'requisition-approve'
  | 'candidate-import'
  | 'evaluation-create'
  | 'communication-create'
  | 'talent-pool-tag'
  | 'candidate-convert'
  | 'interview-schedule'
  | 'offer-create'
  | 'offer-accept'
  | 'program-create'
  | 'session-create'
  | 'enrollment-create'
  | 'enrollment-complete'
  | 'plan-create'
  | 'course-create'
  | 'checkin-create'
  | 'exam-create'
  | 'exam-publish'
  | 'training-feedback-create'

export type TalentPanel = {
  title: string
  body: string
  points: readonly string[]
}

export type TalentPending = {
  label: string
  plannedPath: string
  detail: string
}

export type TalentPageConfig = {
  key: string
  domain: TalentDomain
  title: string
  group: string
  description: string
  readPath: string
  readLabel: string
  columns: readonly string[]
  forms: readonly TalentFormKind[]
  panels: readonly TalentPanel[]
  pending?: TalentPending
  secondaryRead?: string
  routeFilterKey?: string
}

export type TalentRow = Record<string, unknown>

export type TalentOperationResult = {
  kind: TalentFormKind
  response: TalentRow
}

export type TalentPageEntry = {
  key: string
  component: Component
}
