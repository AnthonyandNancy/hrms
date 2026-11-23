#!/usr/bin/env node
/**
 * Runs a script in the frontend workspace when it exists.
 *
 * The monorepo is scaffolded backend-first. Until `frontend/package.json`
 * exists, frontend gates must fail loudly instead of silently passing.
 */
import { existsSync, readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { join } from 'node:path';

const [, , script] = process.argv;

if (!script) {
  console.error('Usage: node scripts/frontend-workspace.mjs <script>');
  process.exit(2);
}

const root = process.cwd();
const frontend = join(root, 'frontend');
const manifestPath = join(frontend, 'package.json');

if (!existsSync(manifestPath)) {
  console.error(`frontend not scaffolded yet: missing ${manifestPath}`);
  process.exit(1);
}

let manifest;
try {
  manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
} catch (error) {
  console.error(`frontend package.json is not valid JSON: ${error.message}`);
  process.exit(1);
}

if (!manifest.scripts || !manifest.scripts[script]) {
  console.error(`frontend does not declare a "${script}" script yet.`);
  process.exit(1);
}

const result = spawnSync('pnpm', ['--dir', frontend, 'run', script], {
  stdio: 'inherit',
  shell: process.platform === 'win32',
});

if (result.error) {
  console.error(`Failed to run frontend ${script}: ${result.error.message}`);
  process.exit(1);
}

process.exit(result.status ?? 1);
