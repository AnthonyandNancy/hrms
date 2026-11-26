export type DictionaryEntry = { label: string; tone?: 'success' | 'warning' | 'danger' | 'info' }

const DICTIONARY: Record<string, Record<string, DictionaryEntry>> = {
  'people.employeeStatus': {
    ACTIVE: { label: '在职', tone: 'success' },
    PROBATION: { label: '试用', tone: 'warning' },
    TERMINATED: { label: '离职', tone: 'info' },
    ARCHIVED: { label: '已归档', tone: 'info' },
  },
  'people.employmentType': {
    FULL_TIME: { label: '全职', tone: 'success' },
    PART_TIME: { label: '兼职', tone: 'info' },
    CONTRACT: { label: '合同制', tone: 'info' },
    INTERN: { label: '实习', tone: 'warning' },
  },
  'payroll.amountUnit': {
    MINOR_UNITS: { label: '最小货币单位', tone: 'info' },
  },
}

export function translate(domain: string, field: string, code: string | null | undefined): DictionaryEntry {
  if (code === null || code === undefined || code.trim() === '') return { label: '—' }
  const entry = DICTIONARY[`${domain}.${field}`]?.[code]
  if (entry !== undefined) return entry
  if (import.meta.env.DEV) console.warn(`[dictionary] unknown code ${domain}.${field}=${code}`)
  return { label: '未知', tone: 'warning' }
}
