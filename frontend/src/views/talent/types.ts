import type { Component } from 'vue'

export type TalentDomain = 'performance' | 'recruitment-requests' | 'recruitment-candidates' | 'training'

export type TalentFormKind =
  | 'cycle-create'
  | 'template-create'
  | 'review-create'
  | 'review-submit'
  | 'review-appeal'
  | 'requisition-create'
  | 'opening-publish'
  | 'candidate-create'
  | 'interview-schedule'
  | 'offer-create'
  | 'offer-accept'
  | 'program-create'
  | 'session-create'
  | 'enrollment-create'
  | 'enrollment-complete'

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
