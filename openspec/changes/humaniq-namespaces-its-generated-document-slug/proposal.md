# Humaniq namespaces its generated-document slug

## Why

A schema slug is global per organisation. `SchemaMapper::find()` matches
`LOWER(slug)`, so two apps declaring one means whichever row is reached first
answers for both, and which one that is depends on install order.

Two apps declared a generated-document schema:

| | filinq `generatedDocument` | humaniq `GeneratedDocument` |
| --- | --- | --- |
| what it is | the rendered document | the HR log of what was rendered for whom |
| carries | caseId, templateId, templateName, templateVersion, format, dataRefs, warnings, generatedBy | employeeId, contractId, payslipId, jaaropgaafId, documentType, templateRef |

## Neither is retired

The directive was that only filinq generates documents, and that is already
true here: `OfferLetterService` resolves `OCA\DocuDesk\Service\DocumentService`
by FQCN and this app renders nothing itself. What collided was the NAME of the
record, not the responsibility.

They also do not model the same thing. filinq's record has no employee,
contract, payslip or jaaropgaaf, and humaniq's has no template version or
format. Folding either into the other would lose columns to no purpose.

So this namespaces rather than migrates: `GeneratedDocument` becomes
`HrGeneratedDocument`, the same move larpinq already made for `skill`, `item`
and `event` (`larping_*`) and for the same reason.

## The half that is not a find-and-replace

`ImportHandler` resolves a schema by `(application, slug)`, and its not-found
branch is not an error path. It CREATES a second, empty schema.

So shipping the renamed descriptors alone would rename nothing on an existing
install: the import would fork the schema, every stored object would stay behind
on the old row reachable by nothing, and the only symptom would be the Documenten
index rendering empty. `MigrateSchemaSlug` renames the row first, scoped to this
app's `application` id — an UPDATE on slug alone would rename filinq's row too,
which is exactly the row this change exists to stop answering for.

It moves no data. An object is bound to its schema by numeric id, and the shard
tables are named `oc_openregister_table_<registerId>_<schemaId>`, so there is no
slug anywhere in the physical layout.

## Where the rename had to land

Four descriptors and three code sites, and a rename that reaches three of them
is what a silent no-op looks like. The test asserts all of them together:

- `lib/Settings/humaniq_register.json` — the register's schema list
- `lib/Settings/humaniq_mock_register.json` — key AND `slug`
- `lib/Settings/register.d/hr-documents.json` — key AND `slug`
- `src/manifest.d/hr-documents.json` — two `"schema":` bindings
- `HrDocumentService::GENERATED_DOCUMENT_SCHEMA`
- `RuleAuditService`'s `loadAll()` literal
- `NlDossierRetentionChecks`'s retention-scope list

The page ids (`GeneratedDocuments`, `GeneratedDocumentDetail`) and route names
are UI identifiers rather than slugs, and are left alone.
