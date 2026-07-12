---
capability: hrmq-docudesk-documents
status: planned
planned_by: openspec/changes/hrmq-docudesk-documents
---

# hrmq-docudesk-documents Specification

**Status**: planned (stub — requirements live in the active change until archive)
**Scope**: hrmq (cross-app leaf; consumes docudesk's template/rendering engine via same-instance PHP services)
**OpenSpec changes**:
- [hrmq-docudesk-documents](../../changes/hrmq-docudesk-documents/) _(active)_ — `GeneratedDocument` record + `HrDocumentService` generating the standard HR documents (arbeidsovereenkomst, aanbiedingsbrief, werkgeversverklaring, getuigschrift) from docudesk-hosted `namespace: hrmq` templates via `DocumentService::generateDocument()` (duck-typed, same-instance, `skipped-no-docudesk` degradation), PDF stored on the object via OpenRegister FileService, occ trigger `hrmq:documents:generate` + one guarded api-call endpoint, evidence rule `nl-contract-schriftelijk`, document pages (kind: code)

## Purpose

Give hrmq templated HR paper without building a template engine: docudesk owns
authoring, versioning, sandboxed Twig rendering, and PDF production; hrmq
contributes data refs and stores the returned PDF on a `GeneratedDocument`
audit record. Supersedes the `spec/document-template-engine` draft (which
proposed duplicating that machinery inside hrmq). Full requirements
(REQ-HDD-001 … REQ-HDD-010): see the change's delta spec at
`openspec/changes/hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md`;
this stub is replaced by the merged spec on archive.
