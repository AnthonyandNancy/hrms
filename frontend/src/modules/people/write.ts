import { resourcePatch, resourcePost } from '@/api/resourceClient'
import { nextIdempotencyKey } from './peopleRead'
import type { PeopleRow } from './types'

export type WriteResult = {
  row: PeopleRow | null
}

function asRow(value: unknown): PeopleRow | null {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) return null
  return value as PeopleRow
}

export async function postRow(
  path: string,
  body: Record<string, unknown>,
  keyPrefix: string,
): Promise<PeopleRow | null> {
  return asRow(
    await resourcePost<unknown>(path, body, {
      idempotencyKey: nextIdempotencyKey(keyPrefix),
    }),
  )
}

export async function patchRow(
  path: string,
  body: Record<string, unknown>,
  keyPrefix: string,
): Promise<PeopleRow | null> {
  return asRow(
    await resourcePatch<unknown>(path, body, {
      idempotencyKey: nextIdempotencyKey(keyPrefix),
    }),
  )
}

export function optionalNumber(value: string): number | null {
  const trimmed = value.trim()
  if (trimmed === '') return null
  const parsed = Number(trimmed)
  return Number.isFinite(parsed) ? parsed : null
}

export function requiredNumber(value: string): number | null {
  const parsed = optionalNumber(value)
  return parsed !== null && parsed > 0 ? parsed : null
}

export function cleanText(value: string): string {
  return value.trim()
}
