<?php

/**
 * PHPUnit bootstrap for the humaniq unit suite.
 *
 * Registers Composer's autoloader (which maps OCA\Humaniq\ to lib/ and
 * OCA\Humaniq\Tests\ to tests/) and the OCP namespace from the nextcloud/ocp
 * dev dependency, so the unit suite runs standalone in a bare php:8.3-cli
 * container — no installed Nextcloud server required. When a full server
 * checkout is present (app mounted under custom_apps), its base.php is
 * loaded opportunistically for integration-leaning tests.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

// Define that we're running PHPUnit.
if (defined('PHPUNIT_RUN') === false) {
	define('PHPUNIT_RUN', 1);
}

// Include Composer's autoloader and register the OCP namespace for standalone runs.
$autoloader = require __DIR__ . '/../vendor/autoload.php';
if (is_dir(__DIR__ . '/../vendor/nextcloud/ocp/OCP') === true) {
	$autoloader->addPsr4('OCP\\', __DIR__ . '/../vendor/nextcloud/ocp/OCP/');
	$autoloader->addPsr4('NCU\\', __DIR__ . '/../vendor/nextcloud/ocp/NCU/');
}

// Doctrine placeholders, loaded BEFORE anything can mock an OCP DB interface.
// IQueryBuilder evaluates constants referencing Doctrine\DBAL\ParameterType at
// parse time, and IDBConnection::getQueryBuilder() returns IQueryBuilder — so
// without these, createMock(IDBConnection::class) dies with
// `Class "Doctrine\DBAL\ParameterType" not found`. Guarded, so a real runtime
// still wins.
require_once __DIR__ . '/stubs/DoctrineStubs.php';

// Bootstrap Nextcloud when a full server environment is available. The include
// is wrapped in a try/catch so unit tests still run in standalone mode (e.g. a
// bare CI container without an installed Nextcloud).
if (file_exists(__DIR__ . '/../../../lib/base.php') === true) {
	try {
		require_once __DIR__ . '/../../../lib/base.php';
	} catch (\Throwable $e) {
		// Nextcloud not fully installed — unit tests continue with vendor stubs only.
	}
}

// OpenRegister's lifecycle guard contract (LifecycleGuardInterface/GuardResult) is
// a sibling-app dependency, not a composer package — humaniq's guards
// (NoSelfApprovalGuard, PayrollRunApprovedGuard) implement/return it, but the
// classes only exist when the OpenRegister app is actually installed alongside
// humaniq. Load the TEST-ONLY stub (tests/stubs/OpenRegisterLifecycleStub.php) when
// they are absent, so the standalone PHPUnit suite can exercise guard logic. Never
// loaded via composer.json autoload, and skipped entirely when the real classes
// are already resolvable (e.g. a full server checkout with OpenRegister installed
// via the base.php include above) — the real classes always win.
if (interface_exists('OCA\\OpenRegister\\Lifecycle\\LifecycleGuardInterface') === false) {
	require __DIR__ . '/stubs/OpenRegisterLifecycleStub.php';
}

// Same rule, different class. Five classes establish OpenRegister's
// availability with class_exists() instead of SettingsService (they do not
// inject it — see the stub's header). Without the name present, that guard
// refuses every call here and those tests fail on a missing app rather than on
// their subject. The stub declares a NAME ONLY; the doubles the tests inject
// through the container are unchanged.
if (class_exists('OCA\\OpenRegister\\Service\\ObjectService') === false) {
	require __DIR__ . '/stubs/OpenRegisterObjectServiceStub.php';
}

// Same rule, different class: AssetDialectMigrationService::schemaMapper()
// establishes availability with class_exists('OCA\OpenRegister\Db\SchemaMapper')
// (hrmq-asset-fleet-merge tasks.md section 13's withAssetHardValidationDisabled()
// fallback). Without the name present, that guard refuses every call here and
// AssetDialectMigrationServiceTest fails on a missing app rather than on its
// subject. The stub declares a NAME ONLY; the fake SchemaMapper the test
// injects through the container is unchanged.
if (class_exists('OCA\\OpenRegister\\Db\\SchemaMapper') === false) {
	require __DIR__ . '/stubs/OpenRegisterSchemaMapperStub.php';
}

// Same rule, different classes: the hours-process listeners
// (TimeEntryStampListener, TimesheetProcessStampListener,
// TimesheetAggregateListener) consume OpenRegister's object lifecycle events
// and ObjectEntity. The stub file mirrors their REAL API (not name-only) so
// the standalone suite can drive the listeners' decision logic; each class is
// individually class_exists()-guarded inside the stub, so the real classes
// always win in a full server checkout.
if (class_exists('OCA\\OpenRegister\\Event\\ObjectCreatingEvent') === false
	|| class_exists('OCA\\OpenRegister\\Event\\ObjectUpdatedEvent') === false
) {
	require __DIR__ . '/stubs/OpenRegisterObjectEventsStub.php';
}

// Same rule, different classes: the payroll flow nodes (lib/Flow/) implement
// OpenRegister's IFlowNode and their listener consumes RegisterFlowNodesEvent
// (payroll-run-as-a-flow design.md D6). IFlowNode is a method-for-method
// mirror of openregister@246222d; the registry/event pair mirrors the REAL
// registration API so the listener test can observe what registered. Loaded
// ONLY when the real interface is absent — on a live instance OpenRegister
// always wins.
if (interface_exists('OCA\\OpenRegister\\Service\\Flow\\IFlowNode') === false) {
	require __DIR__ . '/stubs/OpenRegisterFlowStub.php';
}

// Same rule, different classes: table-declared compliance checks delegate
// matching to OpenRegister's shared decision-table evaluator
// (lib/Standards/TableCheckEvaluator.php). These are VERBATIM copies of OR's
// pure lib/Service/Dmn classes (openregister@d1594ccd, flow-decision-tables;
// only the OR-repo @spec anchors are rewritten to provenance notes), so the
// standalone suite exercises the REAL evaluation semantics instead of a
// hand-scripted fake that could only agree with its author. Loaded ONLY when
// the real classes are absent — on a live instance OpenRegister always wins.
if (class_exists('OCA\\OpenRegister\\Service\\Dmn\\DecisionTableEvaluator') === false) {
	require __DIR__ . '/stubs/OpenRegisterDmn/DecisionEvaluationException.php';
	require __DIR__ . '/stubs/OpenRegisterDmn/UnaryTestEvaluator.php';
	require __DIR__ . '/stubs/OpenRegisterDmn/DecisionTableEvaluator.php';
}
