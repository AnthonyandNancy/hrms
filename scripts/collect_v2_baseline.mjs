#!/usr/bin/env node
import { execFileSync } from 'node:child_process'
import { mkdirSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const workingTreeName = repoRoot.split(/[\\/]/).at(-1)
const mainRepoRoot = resolve(repoRoot, '../..')

const OUTPUT_TAIL_LIMIT = 4000

/**
 * Removes host-specific absolute paths from captured output so committed
 * evidence is reproducible on any machine. Relative paths and command results
 * are preserved.
 */
export function sanitizeOutput(value) {
  let output = String(value)

  for (const [absolutePath, label] of [
    [repoRoot, `<worktree:${workingTreeName}>`],
    [mainRepoRoot, '<repo>'],
    [tmpdir(), '<temp>'],
  ]) {
    if (absolutePath === '') continue
    output = output.split(absolutePath).join(label)
    output = output.split(absolutePath.replaceAll('\\', '/')).join(label)
  }

  output = output
    .replace(/\bnpipe:\/\/[^\s]*/g, '<docker-pipe>')
    .replace(/\b[A-Za-z]:\\Program Files\\[^\s]*/g, '<program-files-path>')
    .replace(/\b[A-Za-z]:\\Users\\[^\s]*/g, '<user-path>')
    .replace(/\b[A-Za-z]:\\[^\s'",)]*/g, '<host-path>')
    .replace(/(?<![\w:])\/(?:home|Users)\/[^\s'",)]*/g, '<host-path>')

  return output
}

function escapeTableCell(value) {
  return String(value).replaceAll('\\', '\\\\').replaceAll('|', '\\|').replaceAll(/\r?\n/g, ' ')
}

export function buildBaselineReport(entries) {
  const lines = [
    '# FactoryHR V2.1 Baseline Evidence',
    '',
    `- Generated: ${new Date().toISOString()}`,
    '',
  ]
  for (const entry of entries) {
    lines.push(`## ${entry.name}`, '', `- command: \`${entry.command}\``, `- exit code: ${entry.exitCode}`, '')
    const output = sanitizeOutput(`${entry.stdout}${entry.stderr}`).trim()
    lines.push('```text', output === '' ? 'NOT RUN' : output.slice(-OUTPUT_TAIL_LIMIT), '```', '')
  }
  return `${lines.join('\n')}\n`
}

export function buildCapabilityInventory(entries) {
  const lines = [
    '# FactoryHR V2.1 Capability Inventory',
    '',
    '- Source: `backend/app/Modules/IAM/Authorization/CapabilityCatalog.php` (code-owned)',
    '- Generation command: `node scripts/collect_v2_baseline.mjs`',
    `- Capability count: ${entries.length}`,
    '',
    '| Capability code | Resource | Actions | Anchor types | Field profile max | Risk | SoD group | Description |',
    '| --- | --- | --- | --- | --- | --- | --- | --- |',
  ]

  for (const entry of entries) {
    const actions = entry.actions.map((action) => `\`${escapeTableCell(action)}\``).join(', ')
    const anchors = entry.anchorTypes.map((anchor) => `\`${escapeTableCell(anchor)}\``).join(', ')
    lines.push(
      `| \`${escapeTableCell(entry.code)}\` | \`${escapeTableCell(entry.resource)}\` | ${actions} | ${anchors} | \`${escapeTableCell(entry.fieldProfile)}\` | \`${escapeTableCell(entry.risk)}\` | ${entry.sodGroup === null ? '—' : `\`${escapeTableCell(entry.sodGroup)}\``} | ${escapeTableCell(entry.description)} |`,
    )
  }

  return `${lines.join('\n')}\n`
}

export function runCommand(name, command, args, { cwd = repoRoot, displayCommand, shell = false } = {}) {
  const recordedCommand = displayCommand ?? [command, ...args].join(' ')
  try {
    const stdout = execFileSync(command, args, {
      cwd,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
      maxBuffer: 32 * 1024 * 1024,
      shell,
    })
    return { name, command: recordedCommand, exitCode: 0, stdout, stderr: '' }
  } catch (error) {
    return {
      name,
      command: recordedCommand,
      exitCode: typeof error.status === 'number' ? error.status : error.code === 'ENOENT' ? 127 : 1,
      stdout: String(error.stdout ?? ''),
      stderr: String(error.stderr ?? error.message),
    }
  }
}

function failedGateSummary(entries) {
  const failed = entries.filter((entry) => entry.exitCode !== 0)
  if (failed.length === 0) return '- None: every executed gate exited 0.'

  return failed
    .map((entry) => `- \`${entry.name}\`: exit code ${entry.exitCode}; command \`${entry.command}\`.`)
    .join('\n')
}

function appendManualVerification(report, { commit, phpVersion, nodeVersion, pnpmVersion, gitStatus, dockerProbe, entries }) {
  return `${report}
## 人工核验

- Git commit: \`${commit.stdout.trim()}\`
- PHP: \`.tools/php/php.exe\` — ${phpVersion.stdout.trim()}
- Node/pnpm: Node ${nodeVersion.stdout.trim()}, pnpm ${pnpmVersion.stdout.trim()}
- MySQL/Docker: NOT RUN. \`docker info\` exited ${dockerProbe.exitCode}; daemon unavailable: ${sanitizeOutput(dockerProbe.stderr.trim().split('\n').at(-1) ?? '')}
- 当前失败门禁清单:
${failedGateSummary(entries)}
- 现有未提交文件（采集时）:

\`\`\`text
${gitStatus.stdout.trim() === '' ? 'clean' : gitStatus.stdout.trim()}
\`\`\`
`
}

function extractCapabilities(php) {
  const phpCode = [
    "require 'vendor/autoload.php';",
    '$catalog = new \\App\\Modules\\IAM\\Authorization\\CapabilityCatalog();',
    '$rows = array_map(static fn ($definition) => [',
    "'code' => $definition->code,",
    "'resource' => $definition->resource,",
    "'actions' => $definition->actions,",
    "'anchorTypes' => array_map(static fn ($anchor) => $anchor->value, $definition->anchorTypes),",
    "'fieldProfile' => $definition->fieldProfileMax->value,",
    "'risk' => $definition->risk,",
    "'sodGroup' => $definition->sodGroup,",
    "'description' => $definition->description,",
    '], $catalog->all());',
    "echo json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);",
  ].join(' ')

  const result = runCommand(
    'capability-inventory',
    php,
    ['-d', 'memory_limit=1G', '-r', phpCode],
    {
      cwd: resolve(repoRoot, 'backend'),
      displayCommand: 'cd backend && ../.tools/php/php.exe -d memory_limit=1G -r <extract CapabilityCatalog as JSON>',
    },
  )

  if (result.exitCode !== 0) {
    throw new Error(`capability inventory extraction failed (exit ${result.exitCode}): ${result.stderr}`)
  }

  return JSON.parse(result.stdout)
}

function main() {
  const php = process.env.PHP_BINARY ?? resolve(repoRoot, '.tools/php/php.exe')
  const phpDisplay = '.tools/php/php.exe'
  const backendCwd = resolve(repoRoot, 'backend')
  const entries = [
    runCommand('git-commit', 'git', ['rev-parse', 'HEAD']),
    runCommand('git-status', 'git', ['status', '--short', '--branch']),
    runCommand('node-version', 'node', ['--version']),
    runCommand('pnpm-version', 'pnpm', ['--version'], { shell: true, displayCommand: 'pnpm --version' }),
    runCommand('php-version', php, ['--version'], { displayCommand: `${phpDisplay} --version` }),
    runCommand('docker-info', 'docker', ['info'], { displayCommand: 'docker info' }),
    runCommand(
      'backend-test',
      php,
      ['-d', 'memory_limit=1G', 'vendor/bin/phpunit', '--colors=never'],
      {
        cwd: backendCwd,
        displayCommand: `cd backend && ../${phpDisplay} -d memory_limit=1G vendor/bin/phpunit --colors=never`,
      },
    ),
    runCommand('backend-pint', php, ['vendor/bin/pint', '--test'], {
      cwd: backendCwd,
      displayCommand: `cd backend && ../${phpDisplay} vendor/bin/pint --test`,
    }),
    runCommand('backend-phpstan', php, ['vendor/bin/phpstan', 'analyse', '--no-progress'], {
      cwd: backendCwd,
      displayCommand: `cd backend && ../${phpDisplay} vendor/bin/phpstan analyse --no-progress`,
    }),
    runCommand('capability-audit', php, ['artisan', 'capability:audit'], {
      cwd: backendCwd,
      displayCommand: `cd backend && ../${phpDisplay} artisan capability:audit`,
    }),
    runCommand('arch-test', php, ['artisan', 'arch:test'], {
      cwd: backendCwd,
      displayCommand: `cd backend && ../${phpDisplay} artisan arch:test`,
    }),
    runCommand('frontend-typecheck', 'pnpm', ['typecheck'], { shell: true, displayCommand: 'pnpm typecheck' }),
    runCommand('frontend-lint', 'pnpm', ['lint'], { shell: true, displayCommand: 'pnpm lint' }),
    runCommand('frontend-unit', 'pnpm', ['test:unit'], { shell: true, displayCommand: 'pnpm test:unit' }),
    runCommand('page-components', 'node', ['scripts/audit_page_components.mjs'], {
      displayCommand: 'node scripts/audit_page_components.mjs',
    }),
    runCommand('frontend-modules', 'node', ['scripts/audit_frontend_modules.mjs'], {
      displayCommand: 'node scripts/audit_frontend_modules.mjs',
    }),
    runCommand('actions-check', 'node', ['scripts/audit_action_manifest.mjs'], {
      displayCommand: 'node scripts/audit_action_manifest.mjs',
    }),
    runCommand('openapi-lint', 'node', ['deploy/scripts/openapi-lint.mjs'], {
      displayCommand: 'node deploy/scripts/openapi-lint.mjs',
    }),
    runCommand('openapi-validate', 'pnpm', ['openapi:validate'], {
      shell: true,
      displayCommand: 'pnpm openapi:validate',
    }),
  ]

  const capabilities = extractCapabilities(php)

  const report = appendManualVerification(buildBaselineReport(entries), {
    commit: entries[0],
    phpVersion: entries[4],
    nodeVersion: entries[2],
    pnpmVersion: entries[3],
    gitStatus: entries[1],
    dockerProbe: entries[5],
    entries,
  })

  const evidenceOut = resolve(repoRoot, 'docs/audit/2026-11-26-v2-baseline-evidence.md')
  const inventoryOut = resolve(repoRoot, 'docs/audit/2026-11-26-v2-capability-inventory.md')
  mkdirSync(dirname(evidenceOut), { recursive: true })
  mkdirSync(dirname(inventoryOut), { recursive: true })
  writeFileSync(evidenceOut, report)
  writeFileSync(inventoryOut, buildCapabilityInventory(capabilities))
  console.log(evidenceOut)
  console.log(inventoryOut)
  console.log(`capabilities=${capabilities.length}`)
}

if (process.argv[1] !== undefined && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  main()
}
