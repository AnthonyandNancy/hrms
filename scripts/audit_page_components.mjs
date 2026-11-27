#!/usr/bin/env node
// Thin CLI wrapper. All logic lives in the import-safe module so Vitest can
// import it without a shebang surviving into the transform step.
import { runCli } from './audit_page_components_lib.mjs'

runCli()
