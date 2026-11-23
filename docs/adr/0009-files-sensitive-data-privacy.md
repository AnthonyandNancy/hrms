# ADR-0009: Private files, sensitive data, field profiles and log redaction

- **Status:** Accepted — 2026-11-24
- **Decision IDs:** D-12, D-13
- **Supersedes:** none
- **Review trigger:** connecting a real KMS or object-store signed URL, introducing a new sensitive field or export purpose, changing field-profile semantics, adding a DLP/watermark pipeline, or enabling production storage/retention without a data-residency and PIPIA review

## Context and trigger

Employee documents, payroll exports, identity numbers, contact data, and
sensitive-field access are cross-cutting privacy concerns. D-12 requires private
storage, encryption and short-lived access; D-13 requires purpose-bound fields,
minimum necessary collection, masking, export guardrails, and privacy evidence.
Once files or field profiles are exposed through an API, changing storage keys,
token lifetimes, or masking rules is expensive and may expose historical data.

The reference implementation demonstrates a private file boundary, field-profile
projection, report/export guardrails, PII encryption, and log redaction. It does
not deliver a production KMS, DLP, document-signing service, consent workflow, or
all-purpose data map. This ADR records the implemented boundary and the gaps that
must remain GATEs.

## Decision

1. **Private objects, metadata-only references.** `FileObject` owns file metadata
   and hides `storage_key`; `LocalPrivateFileStore` writes beneath a
   tenant-scoped private key and records the SHA-256, MIME, size, owner, purpose,
   retention class, and legal-hold flag. Business modules receive a
   `PrivateFileStore`/`FileObjectReader`/`FileMetadataReader` contract and store
   only the file-object id. They do not write `file_objects` or expose storage
   keys.
2. **Tenant-scoped file attachment.** `DocumentService::open()` resolves the
   file through `FileMetadataReader` for the same tenant before creating a
   document; an unknown or cross-tenant file object is rejected. Document API
   responses omit the storage key and hash. The download endpoint requires both
   `people.contract.read` and `people.document.download` and currently returns a
   descriptor (`download_requires_signed_url: true`) rather than a storage key.
3. **Short-lived export access.** `Reporting\Services\ExportService` creates a
   random download token, stores only its SHA-256, caps the link at 24 hours and
   five downloads, caps rows at 50,000, applies a purpose from an allowlist, and
   embeds an export watermark. A download is authorized again for the requesting
   principal and is audited. `ExportJobRunner` writes the CSV through the private
   file store. This is a bearer-token application download, not a pre-signed
   object-store URL.
4. **Field profiles are ordered and row-bound.** `FieldProfile` defines
   `P0_PUBLIC < P1_INTERNAL < P2_SENSITIVE < P3_RESTRICTED`. `FieldSet` derives
   visible, filterable, exportable, and hidden fields from the same binding that
   covers the row; it masks P1/P2 and removes P3 before serialization. P2/P3 are
   not filterable/sortable, P3 is never exported, and returning a P2/P3 field
   emits a field-name-only `sensitive_field_access` audit event. Profiles never
   merge across bindings that do not cover the row.
5. **Export whitelist and purpose.** Report definitions declare their fields and
   profiles. `ReportService::exportRows()` accepts only fields in that
   definition, rejects hidden fields, and applies `FieldSet` masking again on the
   export path. Export purposes are restricted to `BUSINESS_OPERATION`, `AUDIT`,
   `COMPLIANCE`, and `PAYROLL_RECONCILIATION`; a caller cannot invent an
   arbitrary purpose. The `AuthorityBinding` record carries a `purpose_code`.
6. **PII encryption and hashing.** `PersonPrivacy` encrypts identity and mobile
   values with Laravel `Crypt`/`APP_KEY` and hashes identity search values with
   `hash_hmac('sha256', ..., hrms.pii_hmac_key)`. Person model casts keep
   ciphertext hidden, and profile projections omit identity numbers. The
   application config uses AES-256-CBC; no plaintext identity or mobile values
   are persisted in the tested paths.
7. **Logs carry correlation, not payloads.** `RequestLogMiddleware` emits one
   structured line with request id, tenant/user id, route, method, path, status,
   duration, and exception class — never request body, query string, or response
   payload. `SensitiveDataRedactor`/`RedactingLogTap` recursively redact
   sensitive keys and mask identity numbers, email addresses, mobile numbers,
   and card-like values at every configured log channel.
8. **File retention is a class, not an ad hoc delete.** File objects carry
   `purpose`, `retention_class`, and `legal_hold`; the retention service and
   retention matrix are specified separately in ADR-0015. File deletion must go
   through the owning contract; no public URL is issued for private objects.

### Known limitations of this implementation

- There is **no KMS/envelope encryption and no automatic key rotation**. PII
  encryption relies on Laravel's application key; the HMAC key has a
  development fallback (`reference-implementation-change-me`) that must be
  replaced in every real environment. Production key custody, rotation, split
  duties, and data-residency controls are GATEs.
- There is **no DLP engine and no watermarking of People document downloads**.
  Reporting CSV exports have a watermark and row cap; document download is only
  an authorization descriptor in this build, and no signed URL issuer or
  object-store expiry is implemented.
- D-13's complete field–purpose–basis–access–retention five-tuple and consent /
  correction / deletion / withdrawal / PIPIA workflows are **not fully
  implemented**. The code has field profiles, `purpose_code` on bindings,
  export-purpose validation, and a retention matrix, but not a complete
  privacy-purpose catalogue, PIPIA workflow, or data-subject request workflow.
- The export link is a query-string bearer token valid for 24 hours with up to
  five downloads; it is not a one-time, short-lived signed object URL. The token
  can appear in browser history or proxy logs, so production must review this
  against D-12's short-lived-link requirement.
- There is no dedicated file-store or report-download feature test in this
  repository; the file boundary is exercised indirectly by document/PII tests,
  and the export implementation must not be described as fully test-covered.
- `LocalPrivateFileStore` hardcodes `retention_class = REPORT_EXPORT_24H` for
  every stored file, regardless of purpose; a document/contract file stored
  through this adapter would not carry a purpose-specific retention class until
  the store API is extended. This is a review trigger.

## Alternatives considered

| Alternative | Why rejected |
| --- | --- |
| Store files in a public disk and return storage URLs | Anyone with the URL can read employee documents; revoking a URL is unreliable and storage keys leak into clients and logs. |
| Put the raw storage path or SHA-256 in every business row | Couples modules to Platform storage and increases the blast radius of a metadata leak; business modules only need a file-object id and scoped metadata. |
| Rely on frontend masking or field omission | A client can call the API directly; masking must be enforced server-side from the same authorization binding as row scope. |
| Return full sensitive values and log an access event after the response | A log entry does not prevent disclosure; P3 values must never be serialized and P2/P3 access must be audited at projection time. |
| Use a long-lived static download URL | A leaked link remains valid indefinitely; the implementation uses an expiring, download-limited bearer token and again checks the principal. |
| Encrypt PII with a hardcoded application secret or store plaintext for search | Loses key rotation and separation of duties; searchable identity uses a keyed HMAC, not plaintext. |
| Rely on application logs as the privacy audit trail | Logs are not append-only evidence and are themselves subject to redaction/retention; field access uses the audit recorder. |

## Consequences

Positive:

- private bytes are separated from business metadata and never exposed as a
  storage key in ordinary API payloads;
- file attachment is tenant-scoped and document download requires two
  capabilities;
- field profiles and export whitelists prevent P3 leakage and give P1/P2 a
  consistent masking rule;
- export links are expiring, download-limited, watermarked, row-capped, and
  audited;
- PII values are encrypted/hashed and log redaction is enforced at the channel.

Negative / costs:

- private-file access needs a separate signed-URL/file-service integration that
  is not delivered here;
- KMS rotation, key custody, DLP, and a complete privacy-purpose catalogue
  remain operational/legal work;
- masking and profile checks add projection code and can make filters/reports
  less flexible;
- a bearer token in a URL is weaker than a pre-signed object URL and needs
  production review;
- the hardcoded export retention class on stored files can misclassify
  non-export documents.

Data/API/permission/migration/operations/training impact: Platform owns
`file_objects`; People and Reporting reference file ids only. Field profiles and
export purposes are permission surfaces, so changing them requires a capability
review and negative tests. Private-storage configuration is environment-driven;
the Compose skeleton uses an S3-compatible MinIO disk, while local tests use the
private local disk. Operators need a document-disposal and export-review
runbook. No real employee data may be used in tests or seeds (AGENTS.md rule 8).

## Validation

- `php artisan test --filter=FieldProfileTest` — ordered ranks, visibility,
  filterability, exportability, masking, and P3 removal.
- `php artisan test --filter=FieldProjectionTest` — P0/P1/P2/P3 projection,
  non-filterable sensitive fields, 404 for uncovered rows, export unlock, and
  field-name-only sensitive-access audit.
- `php artisan test --filter=LogRedactionTest` — nested context redaction,
  identity/email/mobile masking in free text, real log-channel redaction, and
  request log correlation without payload.
- `php artisan test --filter=FieldPrivacyTest` — identity never appears in
  profile/timeline responses, P1 mobile masking, document metadata hides
  storage key/hash, and sensitive material is not persisted in plaintext.
- `php artisan test --filter=RequirementGapsTest` — cross-tenant file objects
  are rejected, document download requires both capabilities, and the download
  descriptor returns no storage key.
- `php artisan test --filter=PersonDedupTest` — audit and outbox payloads never
  contain identity plaintext.
- `php artisan test --filter=PayrollExportTest` — CSV BOM/hash, no hidden PII
  columns, and exports fail closed before approval.
- `php artisan test --filter=FieldDenialHttpTest` — field denial through HTTP
  returns a controlled error rather than a partial row.
- `php artisan arch:test` — Platform/People/Reporting boundary checks; the
  current repository run reports 16 non-blocking warnings, not a clean zero.

## Security and privacy

Storage keys are hidden from API serialization and returned only to Platform
code. File bytes are private by default; document downloads are capability
guarded and do not expose storage keys. Export tokens are hashed at rest and
expire after 24 hours with a five-download cap; exports are watermarked and
audited. PII is encrypted/hashed, logs are redacted, and field access to P2/P3 is
audited without values. These are reference controls, not a claim of PIPL/ASVS
certification. Production KMS, key rotation, DLP, data residency, consent/
privacy workflow, signed URL issuance, penetration testing, and legal review are
GATEs.

## Review triggers and rollback

Re-review before a production KMS/key-rotation design, before adding any new PII
field or export purpose, before changing P0–P3 semantics, and before issuing
real document signed URLs. Also re-review if an export token can be reused
indefinitely, if the file store is used for non-export retention classes, or if
a new report bypasses `ReportService`/`FieldSet`.

Rollback path: disable the affected export/document surface behind a capability
or feature gate and keep existing file metadata immutable; do not make private
objects public as a rollback. Field profiles and export purposes are additive
compatibility surfaces; removing or weakening one requires a new ADR with a
security review. Owner: platform/security owner with privacy and legal review.
