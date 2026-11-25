import type { Component } from 'vue'

export type PeopleRow = Record<string, unknown>

export type PeopleDomain = 'employees' | 'departments' | 'positions' | 'movements' | 'contracts'

export type PeopleColumn = {
  key: string
  label: string
  width?: string
  minWidth?: string
  align?: 'left' | 'center' | 'right'
}

export type PeopleField = {
  key: string
  label: string
}

export type PeopleGap = {
  label: string
  plannedPath: string
  detail: string
}

export type PeopleProfileResource =
  | 'education'
  | 'experiences'
  | 'contacts'
  | 'certificates'
  | 'change_requests'
  | 'imports'

export type PeopleRead =
  | { kind: 'employee-list' }
  | { kind: 'employee-detail' }
  | { kind: 'employee-profile'; resource: PeopleProfileResource }
  | { kind: 'employee-timeline' }
  | { kind: 'employee-imports' }
  | { kind: 'org-tree' }
  | { kind: 'department-list' }
  | { kind: 'department-members' }
  | { kind: 'department-staffing' }
  | { kind: 'positions' }
  | { kind: 'position-staff' }
  | { kind: 'lifecycle-cases'; caseType?: string }
  | { kind: 'lifecycle-case-detail' }
  | { kind: 'contracts-ledger' }
  | { kind: 'contract-expiring'; withinDays?: number }
  | { kind: 'contract-detail' }
  | { kind: 'contract-templates'; status?: string }
  | { kind: 'contract-amendments'; contractIdParam?: string }

export type PeopleForm =
  | 'employee-create'
  | 'department-create'
  | 'department-member'
  | 'department-move'
  | 'position-editor'
  | 'position-headcount'
  | 'movement-onboarding'
  | 'movement-probation'
  | 'movement-transfer'
  | 'movement-salary'
  | 'movement-offboarding'
  | 'contract-editor'
  | 'contract-renewal'
  | 'contract-changes'
  | 'contract-template-editor'

export type PeoplePageConfig = {
  key: string
  domain: PeopleDomain
  title: string
  group: string
  description: string
  readLabel: string
  read: PeopleRead
  columns: readonly PeopleColumn[]
  detailFields?: readonly PeopleField[]
  form?: PeopleForm
  resource?: PeopleProfileResource
  selectable?: boolean
  gap?: PeopleGap
}

export type PeopleListResult = {
  rows: PeopleRow[]
  total: number
  partial: boolean
}

export type PeopleComponentEntry = {
  key: string
  component: Component
}
