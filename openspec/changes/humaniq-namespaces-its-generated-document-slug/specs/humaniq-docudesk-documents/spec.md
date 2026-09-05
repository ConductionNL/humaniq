# Humaniq docudesk documents

## MODIFIED Requirements

### Requirement: A `HrGeneratedDocument` schema SHALL record every generation attempt in a new register fragment (REQ-HDD-001)

The schema is named `HrGeneratedDocument`, and its slug MUST be
`HrGeneratedDocument` in every descriptor that declares it. It was
`GeneratedDocument`.

A schema slug is global per organisation, and filinq declares a
`generatedDocument` of its own. `SchemaMapper::find()` matches `LOWER(slug)`, so
whichever row it reached first answered for both apps, and which one that was
depended on install order.

Both records stay. They are not the same entity: filinq's is the rendered
document (`templateId`, `templateName`, `templateVersion`, `format`,
`dataRefs`, `caseId`), and this one is the HR log of what was rendered for whom
(`employeeId`, `contractId`, `payslipId`, `jaaropgaafId`, `documentType`). Only
the name collided, and the `Hr` prefix is the same move larpinq made for
`skill`, `item` and `event` (`larping_*`).

"Only filinq generates documents" is already true here and is unchanged by this:
`OfferLetterService` resolves `OCA\DocuDesk\Service\DocumentService` by FQCN and
this app renders nothing itself.

Everything else about the schema — its properties, their types, the enums, the
`$ref`s, the version — is unchanged.

#### Scenario: The schema exists under its namespaced slug

- **WHEN** the register is imported
- **THEN** a schema with slug `HrGeneratedDocument` exists in the humaniq
  register, and an object `{documentType: "arbeidsovereenkomst", employeeId:
  "<uuid>", status: "pending"}` validates against it.

## ADDED Requirements

### Requirement: The slug rename SHALL be migrated on an existing install (REQ-HDD-020)

A repair step SHALL rename the schema row's slug from `GeneratedDocument` to
`HrGeneratedDocument`, scoped to this app's `application` id, and it SHALL run
after `MigrateSchemaApplicationId` and before `InitializeRegister`.

Shipping the renamed slug in the descriptors alone migrates nothing.
`ImportHandler` resolves a schema by `(application, slug)`, and its not-found
branch is not an error path: it CREATES a second, empty schema. Every stored
object would stay behind on the old row, reachable by nothing, with no error
raised and the collection simply rendering empty.

The UPDATE MUST be scoped to the application id as well as the slug. An UPDATE
matching on slug alone would rename filinq's row too, which is the very row this
change exists to stop answering for this app's lookups.

It SHALL move no data. An object is bound to its schema by numeric id (`_schema`,
and the shard tables are named `oc_openregister_table_<registerId>_<schemaId>`),
so a slug is one column on one row and every object, table and link follows it
untouched.

It SHALL be idempotent, and SHALL refuse rather than merge when both slugs are
present: two rows sharing `(application, slug)` means one silently wins every
lookup and the other's objects become unreachable, which is a decision about
data rather than a migration.

#### Scenario: An existing install is renamed in place

- **GIVEN** a schema row with slug `GeneratedDocument` under application `humaniq`
- **WHEN** the repair step runs
- **THEN** that row's slug is `HrGeneratedDocument`, and the UPDATE named the
  application id as well as the slug.

#### Scenario: A second run does nothing

- **GIVEN** a schema row already carrying `HrGeneratedDocument`
- **WHEN** the repair step runs
- **THEN** no row is written.

#### Scenario: Both slugs present is refused, not merged

- **GIVEN** rows carrying both `GeneratedDocument` and `HrGeneratedDocument`
- **WHEN** the repair step runs
- **THEN** no row is written and the refusal is logged.

#### Scenario: An unreadable schemas table does nothing

- **GIVEN** a schemas table that cannot be read
- **WHEN** the repair step runs
- **THEN** no row is written, because "I could not tell" is not "there is
  nothing to rename".
