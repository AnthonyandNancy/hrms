export const PAGE_COMPONENTS = {
  'hr/employees/index': () => import('./EmployeeListPage.vue'),
  'hr/employees/by-id': () => import('./EmployeeDetailPage.vue'),
  'hr/departments/index': () => import('./DepartmentTreePage.vue'),
  'hr/positions/index': () => import('./PositionCatalogPage.vue'),
  'hr/movements/index': () => import('./MovementListPage.vue'),
  'hr/movements/by-id': () => import('./MovementDetailPage.vue'),
  'hr/contracts/index': () => import('./ContractLedgerPage.vue'),
  'hr/contracts/by-id': () => import('./ContractDetailPage.vue'),
} as const

export type PeoplePageComponentKey = keyof typeof PAGE_COMPONENTS
