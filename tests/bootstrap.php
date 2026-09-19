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

/**
 * Tell whether a Nextcloud root is an INSTALLED instance, not just a source tree.
 *
 * lib/base.php from a tree that was never installed still declares `OC` and
 * builds `\OC::$server` before it throws. That server cannot be undone
 * (`OC::$server` is a typed static), so from then on every container lookup in
 * the code under test hits a container that knows none of this app's
 * registrations and autowires from scratch. On 2026-09-08 that recursion took
 * 19 GB of RAM in openregister. The decision therefore has to be made BEFORE
 * base.php is loaded, and the only cheap signal is the `installed` flag.
 *
 * @param string $ncRoot Candidate Nextcloud root.
 *
 * @return bool True when config/config.php declares `installed => true`.
 */
function humaniq_nc_root_is_installed(string $ncRoot): bool {
	$configFile = $ncRoot . '/config/config.php';
	if (is_file($configFile) === false || filesize($configFile) === 0) {
		return false;
	}

	// The config file is a plain `$CONFIG = [...]` script; including it inside a
	// closure keeps `$CONFIG` out of the global scope.
	$config = (static function () use ($configFile): array {
		$CONFIG = [];
		try {
			include $configFile;
		} catch (\Throwable) {
			return [];
		}

		if (is_array($CONFIG) === false) {
			return [];
		}

		return $CONFIG;
	})();

	return ($config['installed'] ?? false) === true;
}

// Bootstrap Nextcloud only when the tree above us is an INSTALLED server. A bare
// source tree is skipped, so the suite stays in pure-unit mode instead of running
// against a half-built container. A root that passes the check and still fails to
// boot stops the run: there is no way back to pure-unit mode once base.php has
// declared OC.
$humaniqNcRoot = dirname(__DIR__, 3);
if (is_file($humaniqNcRoot . '/lib/base.php') === true) {
	if (humaniq_nc_root_is_installed($humaniqNcRoot) === false) {
		fwrite(
			STDERR,
			sprintf(
				"[humaniq/tests/bootstrap] Nextcloud tree at %s is not installed (config/config.php lacks installed => true);"
				. " skipping lib/base.php and running with composer autoload plus stubs only.\n",
				$humaniqNcRoot
			)
		);
	} else {
		try {
			require_once $humaniqNcRoot . '/lib/base.php';
		} catch (\Throwable $e) {
			// The tree IS installed, so the dangerous case this guard exists for
			// (loading a bare source tree) did not happen. base.php still failed
			// part-way, which on CI it does for a Doctrine constant the app's
			// vendor and the server's disagree about.
			//
			// This does NOT abort. `OC::$server` is a typed static, so a
			// half-built container cannot be unset, and aborting was tried: it
			// turned all six PHPUnit legs red on a suite that passes, because
			// nothing in this app resolves anything from the global container.
			// The runaway that motivated this whole guard needs an autowiring
			// lookup to reach the poisoned container, and phpunit.xml's 2G cap
			// now bounds one anyway.
			//
			// So: say plainly that the container is unreliable, and let the pure
			// unit tests run. A container-bound test failing loudly here is the
			// intended outcome.
			fwrite(
				STDERR,
				sprintf(
					"[humaniq/tests/bootstrap] Nextcloud at %s could not finish booting (%s).\n"
					. "  \\OC::\$server now holds a HALF-BUILT container and cannot be unset. Pure unit tests\n"
					. "  continue; anything resolving a service from that container is UNVERIFIED by this run.\n",
					$humaniqNcRoot,
					$e->getMessage()
				)
			);
		}
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

// Same rule, different class: WorkingCalendarReader asks class_exists() for
// openregister's working calendar before reaching for it (working-hours-per-person,
// REQ-WHP-004). Without the name present the reader can only ever answer
// "no working calendar on this instance", and the resolved path would be dark.
if (class_exists('OCA\\OpenRegister\\Service\\WorkingCalendarService') === false) {
	require __DIR__ . '/stubs/OpenRegisterWorkingCalendarStub.php';
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

// Same rule, different contract: RegisterSlugLookup asks OpenRegister which
// slug this instance's humaniq register answers to, through the published
// `RegisterSlugResolverInterface` (ConductionNL/openregister#3579). Its
// availability check is `interface_exists()`, so without the name present the
// lookup answers "absent" unconditionally and NO test could reach the resolved
// branch — the branch that decides what every guard and report does. Unlike the
// name-only ObjectService marker this stub mirrors the REAL API, parameter names
// included, because the lookup calls it by name.
if (interface_exists('OCA\\OpenRegister\\Contract\\RegisterSlugResolverInterface') === false) {
	require __DIR__ . '/stubs/OpenRegisterSlugResolverStub.php';
}

// Same rule, one layer below: when that contract is NOT published (an
// OpenRegister older than #3571, which is what the dev instance was running on
// 2026-09-11), RegisterSlugLookup reads the register table directly and guards
// that with class_exists('OCA\OpenRegister\Db\RegisterMapper'). Without the
// name present the fallback refuses unconditionally and the test for it would
// pass for the wrong reason. Name-only stub; the tests inject their own fake.
if (class_exists('OCA\\OpenRegister\\Db\\RegisterMapper') === false) {
	require __DIR__ . '/stubs/OpenRegisterRegisterMapperStub.php';
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

// Same rule, one version BEHIND on purpose: the leaf listeners contribute a
// LeafDescriptor to RegisterLeafProvidersEvent. The stub mirrors the shape
// OpenRegister published BEFORE #3956 added `loadStrategy` and the `LOADS_*`
// constants, because that is the instance the listeners have to survive: on
// it, reading the constant is an Error their own catch swallows and the leaf
// is simply absent. Stubbing the old shape makes this suite that instance.
if (class_exists('OCA\\OpenRegister\\Service\\Integration\\LeafDescriptor') === false) {
	require __DIR__ . '/stubs/OpenRegisterLeafStub.php';
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
