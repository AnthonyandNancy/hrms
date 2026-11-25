import { readFileSync, readdirSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const registry = readFileSync(join(root, 'frontend/src/router/registry.ts'), 'utf8')
const components = readFileSync(join(root, 'frontend/src/router/pageComponents.ts'), 'utf8')
const openapi = readFileSync(join(root, 'docs/openapi/openapi.yaml'), 'utf8')

// Split on the single-line generated entries and the multiline base entries.
// Every entry contains exactly one "path:" property.
// Entries start with `{cluster: 'K..', path: ...` when they belong to a gap
// cluster, otherwise with `{path: ...`. Base entries are multiline and put
// `path:` on the next line, so split on the entry boundary, not on path alone.
// Two-space open brace at line start is an entry boundary for both single-line
// generated entries and multiline base entries; nested objects are indented deeper.
const entryStart = new RegExp(String.fromCharCode(10) + ' {2}\{', 'g')
const boundaries = [...registry.matchAll(entryStart)].map((match) => match.index + 1)
const chunks = boundaries.map((start, index) =>
  registry.slice(start, index + 1 < boundaries.length ? boundaries[index + 1] : registry.length),
)

function first(source, pattern) {
  return source.match(pattern)?.[1] ?? ''
}

const entries = chunks
  .filter((chunk) => chunk.includes('requiredApis:'))
  .map((chunk) => {
    const path = first(chunk, /path: '([^']+)'/)
    const name = first(chunk, /name: '([^']+)'/)
    const componentKey = first(chunk, /componentKey: '([^']+)'/)
    const capability = first(chunk, /capability: '([^']+)'/)
    const cluster = first(chunk, /cluster: '(K(?:[1-9]|1[0-2]))'/)
    const apiBlock = chunk.slice(chunk.indexOf('requiredApis:'), chunk.indexOf(']', chunk.indexOf('requiredApis:')) + 1)
    const apis = [...apiBlock.matchAll(/'([^']+)'/g)].map((match) => match[1])
    return { path, name, componentKey, capability, cluster, apis }
  })
  .filter((entry) => entry.path !== '')

const openapiPaths = new Set([...openapi.matchAll(/^  (\/v1\/[^:]+):$/gm)].map((match) => match[1]))
const DOMAINS = [
  { prefixes: ['hr/'], dir: 'frontend/src/views/people' },
  { prefixes: ['attendance/', 'leave/', 'overtime/', 'travel/', 'payroll/'], dir: 'frontend/src/views/timepay' },
  { prefixes: ['performance/', 'recruitment/', 'training/'], dir: 'frontend/src/views/talent' },
  { prefixes: ['dashboard/', 'reports/', 'notices/', 'account', 'system/', 'workflow', 'self-service', 'benefits', 'notifications'], dir: 'frontend/src/views/platform' },
]
const domainSources = new Map(
  DOMAINS.map((domain) => [
    domain.dir,
    readdirSync(join(root, domain.dir)).filter((name) => name.endsWith('.ts')).map((name) => readFileSync(join(root, domain.dir, name), 'utf8')).join('\n'),
  ]),
)
function matureKey(key) {
  if (components.includes(`'${key}':`)) return true
  const domain = DOMAINS.find((candidate) => candidate.prefixes.some((prefix) => key === prefix || key.startsWith(prefix)))
  return domain !== undefined && (domainSources.get(domain.dir) ?? '').includes(`'${key}'`)
}
const matureKeys = { has: matureKey }

const rows = entries.map((entry) => {
  const status = matureKeys.has(entry.componentKey) ? 'MATURE' : 'FALLBACK'
  const apiGaps = entry.apis.filter((operation) => {
    const path = operation.slice(operation.indexOf(' ') + 1)
    return !openapiPaths.has(path.replace(/\{[^}]+\}/g, '{id}'))
  })
  return { ...entry, status, apiGaps }
})

const counts = rows.reduce((acc, row) => {
  acc[row.status] = (acc[row.status] ?? 0) + 1
  return acc
}, {})

const CLUSTER_NAMES = {
  K1: '组织岗位完整性', K2: '合同全生命周期', K3: '员工文档', K4: '考勤规则与排班',
  K5: '加班与调休', K6: '差旅与费用', K7: '薪酬档案与调薪', K8: '绩效全周期',
  K9: '招聘全漏斗', K10: '培训全周期', K11: '平台治理与公告', K12: '报表与订阅',
}
const { readGapMarkers } = await import('./lib/gapMarkers.mjs')
const gapMarkers = readGapMarkers(root)
const clusterByComponentKey = new Map(rows.map((row) => [row.componentKey, row.cluster]))

const clusterRows = Object.entries(CLUSTER_NAMES).map(([cluster, name]) => {
  const pages = rows.filter((row) => row.cluster === cluster)
  return {
    cluster,
    name,
    pages: pages.length,
    mature: pages.filter((row) => row.status === 'MATURE').length,
    apiGaps: pages.reduce((total, row) => total + row.apiGaps.length, 0),
    backendGaps: [...gapMarkers.keys].filter((key) => clusterByComponentKey.get(key) === cluster).length,
  }
})

const wp = {
  WP01: ['DONE', 'DONE', 'DONE', 'n/a', 'PENDING', '基础工程与契约生成本地门禁通过，真实栈待最终验证'],
  WP02: ['DONE', 'DONE', 'DONE', 'PARTIAL', 'PENDING', 'IAM 授权快照、14 角色与 160 页目录完成；页面接入待验收'],
  WP03: ['DONE', 'DONE', 'DONE', 'PARTIAL', 'PENDING', '字段投影、锚点与负测存在；平台字段策略页待成熟视图'],
  WP04: ['DONE', 'DONE', 'DONE', 'PARTIAL', 'PENDING', '工作流内核完成；通知/审计平台页待成熟视图'],
  WP05: ['DONE', 'DONE', 'DONE', 'PARTIAL', 'PENDING', '部门/岗位 API 与编制完成；页面待成熟视图'],
  WP06: ['DONE', 'DONE', 'DONE', 'PARTIAL', 'PENDING', '员工档案子资源 API 完成；扩展页待成熟视图'],
  WP07: ['DONE', 'DONE', 'DONE', 'PARTIAL', 'PENDING', '生命周期内核完成；异动页面待成熟视图'],
  WP08: ['DONE', 'DONE', 'DONE', 'PARTIAL', 'PENDING', '合同台账/续签完成；页面待成熟视图'],
  WP09: ['DONE', 'DONE', 'DONE', 'PARTIAL', 'PENDING', '日历/班次 API 完成；规则页待成熟视图'],
  WP10: ['DONE', 'DONE', 'DONE', 'PARTIAL', 'PENDING', '月结/重算锁定完成；页面待成熟视图'],
  WP11: ['DONE', 'DONE', 'DONE', 'PARTIAL', 'PENDING', '假期账本与类型/政策读接口完成；页面待成熟视图'],
  WP12: ['DONE', 'DONE', 'DONE', 'PARTIAL', 'PENDING', '加班单次结算完成；页面待成熟视图'],
  WP13: ['DONE', 'DONE', 'DONE', 'PARTIAL', 'PENDING', '出差申请/取消完成；政策/费用页面待成熟视图'],
  WP14: ['DONE', 'DONE', 'DONE', 'PARTIAL', 'PENDING', '薪资参数/方案 API 存在；页面待成熟视图'],
  WP15: ['DONE', 'DONE', 'DONE', 'PARTIAL', 'PENDING', '批次/工资条/冲正内核完成；页面待成熟视图'],
  WP16: ['DONE', 'DONE', 'DONE', 'PARTIAL', 'PENDING', '绩效模板权重红线完成；页面待成熟视图'],
  WP17: ['DONE', 'DONE', 'DONE', 'PARTIAL', 'PENDING', '招聘需求/开放岗位红线完成；页面待成熟视图'],
  WP18: ['DONE', 'DONE', 'DONE', 'PARTIAL', 'PENDING', '候选人/Offer API 存在；页面待成熟视图'],
  WP19: ['DONE', 'DONE', 'DONE', 'PARTIAL', 'PENDING', '培训报名幂等完成；页面待成熟视图'],
  WP20: ['DONE', 'DONE', 'DONE', 'PARTIAL', 'PENDING', '公告 API 存在；页面待成熟视图'],
  WP21: ['DONE', 'DONE', 'DONE', 'PARTIAL', 'PENDING', '无任职 409 与自助空态完成；自助门户待成熟视图'],
  WP22: ['DONE', 'DONE', 'DONE', 'PARTIAL', 'PENDING', '仪表盘 API 存在；6 个子页待成熟视图'],
  WP23: ['DONE', 'DONE', 'DONE', 'PARTIAL', 'PENDING', '报表 API 存在；9 个子页待成熟视图'],
  WP24: ['DONE', 'DONE', 'DONE', 'PARTIAL', 'PENDING', '审计/文件内核存在；平台页待成熟视图'],
  WP25: ['DONE', 'PARTIAL', 'n/a', 'n/a', 'PENDING', '安全/混沌/压测脚本存在；真实栈与备份演练待执行'],
  WP26: ['DONE', 'PARTIAL', 'n/a', 'PARTIAL', 'PENDING', '发布追溯已贯通；最终全量验收待执行'],
}

const lines = [
  '# V1.1 覆盖矩阵（机器生成事实 + 人工工作包映射）',
  '',
  '> 本文件由 `node scripts/generate_coverage_matrix.mjs` 生成。`MATURE` 表示有专属业务视图；',
  '> `FALLBACK` 表示注册路由可打开且走真实 API，但仍使用通用 ResourcePage；`MISSING` 表示无渲染器。',
  '> 回退页不得在验收中声称业务完成。API 路径按 OpenAPI 精确匹配，带参数的路径需人工复核。',
  '',
  '## 1. 汇总',
  '',
  `- 注册表条目：**${rows.length}**`,
  `- 专属业务视图（MATURE）：**${counts.MATURE ?? 0}**`,
  `- 通用回退（FALLBACK）：**${counts.FALLBACK ?? 0}**`,
  `- 无渲染器（MISSING）：**${counts.MISSING ?? 0}**`,
  `- 有 API 路径未在 OpenAPI 中登记的页面：**${rows.filter((row) => row.apiGaps.length > 0).length}**`,
  '',
  '## 2. 工作包状态',
  '',
  '| 工作包 | 代码 | 测试 | 真实 API | 页面成熟度 | 真实栈 E2E | 结论 |',
  '|---|---|---|---|---|---|---|',
]

for (const [code, values] of Object.entries(wp)) {
  lines.push(`| ${code} | ${values[0]} | ${values[1]} | ${values[2]} | ${values[3]} | ${values[4]} | ${values[5]} |`)
}

lines.push(
  '',
  '## 3. 能力集群（K1–K12）',
  '',
  '| 集群 | 名称 | 页面数 | 成熟视图 | 后端缺口页 | 缺失 API 声明 |',
  '|---|---|---:|---:|---:|---:|',
)
for (const row of clusterRows) {
  lines.push(`| ${row.cluster} | ${row.name} | ${row.pages} | ${row.mature} | ${row.backendGaps} | ${row.apiGaps} |`)
}
lines.push(
  '',
  `- 集群页面合计：**${clusterRows.reduce((total, row) => total + row.pages, 0)}**（契约 63）。`,
  `- 仍有后端缺口标记的页面：**${gapMarkers.total}**（目标 0；按域 ${Object.entries(gapMarkers.byStyle).map(([domain, keys]) => `${domain} ${keys.length}`).join('、')}）。`,
  `- 仍有缺失 API 声明的集群：**${clusterRows.filter((row) => row.apiGaps > 0).map((row) => row.cluster).join(', ') || '无'}**。`,
  '',
)

lines.push('', '## 4. 页面逐项状态', '', '| # | 页面 | 路由 | 能力 | 集群 | 状态 | 缺失 API 声明 |', '|---:|---|---|---|---|---|---|')
rows.forEach((row, index) => {
  lines.push(`| ${index + 1} | ${row.name} | \`${row.path}\` | \`${row.capability}\` | ${row.cluster || '—'} | ${row.status} | ${row.apiGaps.join('<br>') || '—'} |`)
})

lines.push(
  '',
  '## 5. 验收口径',
  '',
  '- `--require-mature` 必须在 160/160 成熟视图时通过；当前通过前不得声称页面 100%。',
  '- 真实栈 E2E 与备份恢复演练必须在 Compose 栈执行并保留日志。',
  '- 任何 API gap 必须对应显式“后端接口待补”页面状态，不得伪造数据。',
  '- 集群页面数之和必须恒等于 63；任一集群的 `gap`/`pending` 标记清零才可标记完成。',
  '',
)

writeFileSync(join(root, 'docs/audit/v11-coverage-matrix.md'), `${lines.join('\n')}\n`, 'utf8')
console.log(`wrote coverage matrix (${rows.length} pages; mature=${counts.MATURE ?? 0}, fallback=${counts.FALLBACK ?? 0})`)
