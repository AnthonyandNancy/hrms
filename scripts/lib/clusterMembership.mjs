/**
 * Frozen cluster membership for the 63 gap pages.
 *
 * Counts alone are not a contract: swapping two same-sized clusters keeps every
 * total identical while silently reassigning pages. The audit and the frontend
 * spec both compare against this exact map.
 *
 * Generated once from docs/superpowers/specs/2026-11-25-v11-gap-completion-design.md
 * §3 and the registry. Update only when the spec itself changes.
 *
 * @type {Record<string, 'K1'|'K2'|'K3'|'K4'|'K5'|'K6'|'K7'|'K8'|'K9'|'K10'|'K11'|'K12'>}
 */
export const CLUSTER_MEMBERSHIP = {
  'attendance/closing': 'K4',
  'attendance/devices': 'K4',
  'attendance/rules': 'K4',
  'attendance/schedules': 'K4',
  'benefits': 'K11',
  'hr/contracts/changes': 'K2',
  'hr/contracts/templates': 'K2',
  'hr/employees/by-id/files': 'K3',
  'hr/movements/secondment': 'K1',
  'hr/positions/grades': 'K1',
  'hr/positions/requirements': 'K1',
  'hr/positions/staff': 'K1',
  'leave/cancellations': 'K5',
  'notices/approval': 'K11',
  'notices/archive': 'K11',
  'notices/audience': 'K11',
  'notices/reads': 'K11',
  'notices/templates': 'K11',
  'notifications': 'K11',
  'overtime/confirm': 'K5',
  'overtime/index': 'K5',
  'overtime/timeoff': 'K5',
  'payroll/adjustments': 'K7',
  'payroll/employee-plans': 'K7',
  'performance/calibration': 'K8',
  'performance/feedback': 'K8',
  'performance/goals': 'K8',
  'performance/indicators': 'K8',
  'performance/reports': 'K8',
  'performance/results': 'K8',
  'recruitment/candidates/communications': 'K9',
  'recruitment/candidates/convert': 'K9',
  'recruitment/candidates/evaluations': 'K9',
  'recruitment/candidates/imports': 'K9',
  'recruitment/candidates/pipeline': 'K9',
  'recruitment/candidates/talent-pool': 'K9',
  'recruitment/requests/approval': 'K9',
  'recruitment/requests/board': 'K9',
  'recruitment/requests/budget': 'K9',
  'recruitment/requests/reports': 'K9',
  'reports/attendance': 'K12',
  'reports/builder': 'K12',
  'reports/exports': 'K12',
  'reports/subscriptions': 'K12',
  'reports/talent': 'K12',
  'system/system-audit': 'K11',
  'system/system-calendars': 'K11',
  'system/system-data-scopes': 'K11',
  'system/system-field-policies': 'K11',
  'system/system-menus': 'K11',
  'system/system-permissions': 'K11',
  'system/system-roles': 'K11',
  'system/system-settings': 'K11',
  'training/checkins': 'K10',
  'training/courses': 'K10',
  'training/exams': 'K10',
  'training/feedback': 'K10',
  'training/index': 'K10',
  'training/records': 'K10',
  'training/reports': 'K10',
  'travel/approval': 'K6',
  'travel/expenses': 'K6',
  'travel/policies': 'K6',
}

export const CLUSTER_COUNTS = Object.values(CLUSTER_MEMBERSHIP).reduce((counts, cluster) => {
  counts[cluster] = (counts[cluster] ?? 0) + 1
  return counts
}, {})
