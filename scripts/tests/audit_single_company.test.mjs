import assert from 'node:assert/strict'
import test from 'node:test'
import {
  MIGRATIONS_DIRECTORY,
  PREFLIGHT_FILE,
  PREFLIGHT_MARKER,
  auditSingleCompany,
} from '../audit_single_company.mjs'

test('fails on tenant and legal-entity hits with file:line diagnostics', () => {
  const result = auditSingleCompany({
    files: [
      {
        path: 'backend/app/Example.php',
        source: [
          '<?php',
          "$tenant = 'tenant_id';",
          "$anchor = 'LEGAL_ENTITY';",
          "$header = 'X-Legal-Entity-Id';",
          "$selected = 'activeLegalEntityId';",
        ].join('\n'),
      },
    ],
  })

  assert.equal(result.ok, false)
  assert.deepEqual(result.failures, [
    'backend/app/Example.php:2',
    'backend/app/Example.php:3',
    'backend/app/Example.php:4',
    'backend/app/Example.php:5',
  ])
})

test('ignores only the exact marked preflight lines in the exact preflight file', () => {
  const result = auditSingleCompany({
    files: [
      {
        path: PREFLIGHT_FILE,
        source: [
          '<?php',
          "['tenant_id', 'legal_entity_id'], // single-company:preflight-only",
          "->where('anchor_type', 'LEGAL_ENTITY')->count() // single-company:preflight-only",
          "$unmarked = 'legal_entity_id';",
        ].join('\n'),
      },
    ],
  })

  assert.equal(result.ok, false)
  assert.deepEqual(result.failures, [`${PREFLIGHT_FILE}:4`])
})

test('rejects file-wide and directory-wide marker exemptions', () => {
  const sibling = 'backend/app/Modules/Organization/Commands/UnmarkedSibling.php'
  const result = auditSingleCompany({
    files: [
      {
        path: sibling,
        source: [
          '<?php',
          "$first = 'tenant_id'; // single-company:preflight-only",
          "$second = 'legal_entity_id';",
        ].join('\n'),
      },
      {
        path: PREFLIGHT_FILE,
        source: [
          '<?php',
          "$first = 'tenant_id'; // single-company:preflight-only",
          "$second = 'LEGAL_ENTITY';",
        ].join('\n'),
      },
    ],
  })

  assert.equal(result.ok, false)
  assert.deepEqual(result.failures, [
    `${sibling}:2`,
    `${sibling}:3`,
    `${PREFLIGHT_FILE}:3`,
  ])
})

test('allowlists only the migrations directory by path', () => {
  const result = auditSingleCompany({
    files: [
      {
        path: `${MIGRATIONS_DIRECTORY}2026_11_26_000320_drop_legacy_columns.php`,
        source: "<?php\n$table->dropColumn(['tenant_id', 'legal_entity_id']);",
      },
      {
        path: 'backend/app/Modules/Organization/Models/Legacy.php',
        source: "<?php\n$legacy = 'tenant_id';",
      },
    ],
  })

  assert.equal(result.ok, false)
  assert.deepEqual(result.failures, ['backend/app/Modules/Organization/Models/Legacy.php:2'])
})
