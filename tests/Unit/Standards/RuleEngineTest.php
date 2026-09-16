<?php

/**
 * Unit tests for RuleEngine, the executable layer over RuleCatalogue.
 *
 * Covers jurisdiction scoping (an NL-only rule does not fire for a US object, an
 * EU-wide rule fires for every EU member state and not for the US, a `global`
 * rule fires everywhere), the `hasMandatory()` block decision, and that
 * `checkedRuleIds()` only returns ids that are machine-checkable catalogue rules.
 *
 * Closes task 3.2 of the archived humaniq-test-coverage-baseline change, which
 * was archived with this test still unwritten.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Standards
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-002
 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards;

use OCA\Humaniq\Standards\RuleCatalogue;
use OCA\Humaniq\Standards\RuleEngine;
use OCA\Humaniq\Standards\Violation;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RuleEngine jurisdiction scoping and the block decision.
 *
 * RuleEngine reads the whole catalogue on construction, so every test here
 * executes the catalogue and the per-domain check classes it holds. With
 * `beStrictAboutCoverageMetadata` on and coverage collected in CI, an executed
 * class that is neither covered nor used makes the test risky, and
 * `failOnRisky` turns one risky test into a red cell with zero failing
 * assertions. Locally the suite runs `--no-coverage`, so this never shows up.
 * The `@uses` lines below name exactly what the engine pulls in.
 *
 * @covers \OCA\Humaniq\Standards\RuleEngine
 *
 * @uses \OCA\Humaniq\Standards\Checks\CompChecks
 * @uses \OCA\Humaniq\Standards\Checks\EuUsPayrollChecks
 * @uses \OCA\Humaniq\Standards\Checks\NlAbpChecks
 * @uses \OCA\Humaniq\Standards\Checks\NlAbsenceChecks
 * @uses \OCA\Humaniq\Standards\Checks\NlAdministratieChecks
 * @uses \OCA\Humaniq\Standards\Checks\NlAorChecks
 * @uses \OCA\Humaniq\Standards\Checks\NlAssetChecks
 * @uses \OCA\Humaniq\Standards\Checks\NlAtsChecks
 * @uses \OCA\Humaniq\Standards\Checks\NlAttendanceChecks
 * @uses \OCA\Humaniq\Standards\Checks\NlCaoChecks
 * @uses \OCA\Humaniq\Standards\Checks\NlDgaChecks
 * @uses \OCA\Humaniq\Standards\Checks\NlDocumentChecks
 * @uses \OCA\Humaniq\Standards\Checks\NlDossierRetentionChecks
 * @uses \OCA\Humaniq\Standards\Checks\NlEngineChecks
 * @uses \OCA\Humaniq\Standards\Checks\NlFleetChecks
 * @uses \OCA\Humaniq\Standards\Checks\NlGlPostChecks
 * @uses \OCA\Humaniq\Standards\Checks\NlHr21Checks
 * @uses \OCA\Humaniq\Standards\Checks\NlLeaveChecks
 * @uses \OCA\Humaniq\Standards\Checks\NlNetPayChecks
 * @uses \OCA\Humaniq\Standards\Checks\NlOffboardingChecks
 * @uses \OCA\Humaniq\Standards\Checks\NlOnboardingChecks
 * @uses \OCA\Humaniq\Standards\Checks\NlOrgChecks
 * @uses \OCA\Humaniq\Standards\Checks\NlPayrollChecks
 * @uses \OCA\Humaniq\Standards\Checks\NlPensionFilingChecks
 * @uses \OCA\Humaniq\Standards\Checks\NlPerformanceChecks
 * @uses \OCA\Humaniq\Standards\Checks\NlRetroChecks
 * @uses \OCA\Humaniq\Standards\Checks\NlRosterChecks
 * @uses \OCA\Humaniq\Standards\Checks\NlSignalChecks
 * @uses \OCA\Humaniq\Standards\Checks\NlSinglePersonChecks
 * @uses \OCA\Humaniq\Standards\Checks\NlStagiairChecks
 * @uses \OCA\Humaniq\Standards\Checks\NlTravelExpenseChecks
 * @uses \OCA\Humaniq\Standards\Checks\NlUitzendChecks
 * @uses \OCA\Humaniq\Standards\Checks\NlWageGarnishmentChecks
 * @uses \OCA\Humaniq\Standards\Checks\NlWageTaxFilingChecks
 * @uses \OCA\Humaniq\Standards\Checks\NlWkrChecks
 * @uses \OCA\Humaniq\Standards\Checks\NlWntChecks
 * @uses \OCA\Humaniq\Standards\RuleCatalogue
 * @uses \OCA\Humaniq\Standards\TableCheckRegistry
 * @uses \OCA\Humaniq\Standards\Violation
 */
final class RuleEngineTest extends TestCase {

	/**
	 * A late monthly loonaangifte: tijdvak and retention are fine, the submission
	 * is after the deadline.
	 *
	 * @var array<string, mixed>
	 */
	private const LATE_NL_FILING = [
		'filingType' => 'loonaangifte',
		'tijdvak' => 'maand',
		'electronicallyFiled' => true,
		'period' => '2026-01',
		'retainedUntil' => '2033-12-31',
		'deadline' => '2026-02-27',
		'submittedDate' => '2026-03-15',
	];

	/**
	 * Reset memoised engine and catalogue state before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		RuleEngine::reset();
		RuleCatalogue::reset();
	}//end setUp()

	/**
	 * Collect the rule ids from a set of violations.
	 *
	 * @param array<int, Violation> $violations Violations to inspect.
	 *
	 * @return array<int, string>
	 */
	private function ruleIds(array $violations): array {
		return array_map(static fn (Violation $violation): string => $violation->ruleId, $violations);
	}//end ruleIds()

	/**
	 * The NL-only `nl-loonaangifte-termijn` rule fires under an NL audit and not
	 * under a US audit.
	 *
	 * @return void
	 */
	public function testNlOnlyRuleDoesNotFireForUsContext(): void {
		$nl = $this->ruleIds(RuleEngine::evaluate('LoonaangifteFiling', self::LATE_NL_FILING, ['jurisdiction' => 'NL']));
		$this->assertContains('nl-loonaangifte-termijn', $nl, 'The NL deadline rule must fire under an NL audit.');

		$us = $this->ruleIds(RuleEngine::evaluate('LoonaangifteFiling', self::LATE_NL_FILING, ['jurisdiction' => 'US']));
		$this->assertNotContains('nl-loonaangifte-termijn', $us, 'The NL-only rule must not fire under a US audit.');
	}//end testNlOnlyRuleDoesNotFireForUsContext()

	/**
	 * The EU-wide `eu-a1-posted-worker` rule fires for a posted worker without an
	 * A1 certificate under every EU member-state audit.
	 *
	 * @return void
	 */
	public function testEuRuleFiresForEveryEuMemberState(): void {
		// Deliberately missing a1CertificateNumber and a1ValidUntil.
		$postedNoA1 = ['postedWorker' => true, 'startDate' => '2026-01-01'];

		foreach (RuleEngine::EU_MEMBER_STATES as $state) {
			$ids = $this->ruleIds(RuleEngine::evaluate('Employee', $postedNoA1, ['jurisdiction' => $state]));
			$this->assertContains('eu-a1-posted-worker', $ids, 'The EU A1 rule must fire for EU member state ' . $state . '.');
		}
	}//end testEuRuleFiresForEveryEuMemberState()

	/**
	 * The EU-wide rule does not fire for a non-EU jurisdiction.
	 *
	 * @return void
	 */
	public function testEuRuleDoesNotFireForNonEuContext(): void {
		$postedNoA1 = ['postedWorker' => true, 'startDate' => '2026-01-01'];
		$ids = $this->ruleIds(RuleEngine::evaluate('Employee', $postedNoA1, ['jurisdiction' => 'US']));

		$this->assertNotContains('eu-a1-posted-worker', $ids, 'The EU A1 rule must not fire under a US audit.');
	}//end testEuRuleDoesNotFireForNonEuContext()

	/**
	 * A `global` rule fires under every audit jurisdiction.
	 *
	 * @return void
	 */
	public function testGlobalRuleFiresEverywhere(): void {
		// A posted run with no GL figures fails the global GL-reconciliation
		// control. The control only applies once a run is posted or paid.
		foreach (['NL', 'US', 'DE', 'FR'] as $jurisdiction) {
			$ids = $this->ruleIds(RuleEngine::evaluate('PayrollRun', ['status' => 'posted'], ['jurisdiction' => $jurisdiction]));
			$this->assertContains(
				'xc-payroll-gl-reconciliation',
				$ids,
				'The global GL-reconciliation rule must fire under a ' . $jurisdiction . ' audit.'
			);
		}
	}//end testGlobalRuleFiresEverywhere()

	/**
	 * hasMandatory() is true exactly when at least one violation is `mandatory`.
	 *
	 * @return void
	 */
	public function testHasMandatoryReflectsMandatorySeverity(): void {
		$this->assertFalse(RuleEngine::hasMandatory([]), 'No violations means nothing to block.');

		$softOnly = [new Violation('r1', 'conditional', 's', ''), new Violation('r2', 'recommended', 's', '')];
		$this->assertFalse(RuleEngine::hasMandatory($softOnly), 'Conditional and recommended violations do not block.');

		$withMandatory = [new Violation('r1', 'recommended', 's', ''), new Violation('r2', 'mandatory', 's', '')];
		$this->assertTrue(RuleEngine::hasMandatory($withMandatory), 'One mandatory violation is enough to block.');
	}//end testHasMandatoryReflectsMandatorySeverity()

	/**
	 * A late NL filing produces a mandatory violation, so hasMandatory() on the
	 * engine's own output is true.
	 *
	 * @return void
	 */
	public function testHasMandatoryTrueForLateNlFiling(): void {
		$violations = RuleEngine::evaluate('LoonaangifteFiling', self::LATE_NL_FILING, ['jurisdiction' => 'NL']);

		$this->assertTrue(RuleEngine::hasMandatory($violations));
	}//end testHasMandatoryTrueForLateNlFiling()

	/**
	 * A posted run with no GL figures trips only recommended global controls, so
	 * hasMandatory() is false.
	 *
	 * @return void
	 */
	public function testHasMandatoryFalseForRecommendedOnlyViolations(): void {
		$violations = RuleEngine::evaluate('PayrollRun', ['status' => 'posted'], ['jurisdiction' => 'NL']);

		$this->assertNotEmpty($violations, 'A posted run without GL figures should trip the recommended GL controls.');
		$this->assertFalse(RuleEngine::hasMandatory($violations));
	}//end testHasMandatoryFalseForRecommendedOnlyViolations()

	/**
	 * Every id from checkedRuleIds() is a machine-checkable catalogue rule: the
	 * engine cannot enforce a rule the catalogue does not mark machine-checkable.
	 *
	 * @return void
	 */
	public function testCheckedRuleIdsAreMachineCheckableCatalogueRules(): void {
		$machineIds = array_map(static fn (array $rule): string => (string)$rule['id'], RuleCatalogue::machineCheckable());
		$checked = RuleEngine::checkedRuleIds();

		$this->assertNotEmpty($checked);
		foreach ($checked as $ruleId) {
			$this->assertContains($ruleId, $machineIds, 'Enforced rule ' . $ruleId . ' must be a machine-checkable catalogue rule.');
		}
	}//end testCheckedRuleIdsAreMachineCheckableCatalogueRules()

	/**
	 * supportedTypes() covers the five compliance-checked object types.
	 *
	 * @return void
	 */
	public function testSupportedTypesCoverKnownObjectTypes(): void {
		$types = RuleEngine::supportedTypes();

		foreach (['Employee', 'EmploymentContract', 'Payslip', 'PayrollRun', 'LoonaangifteFiling'] as $expected) {
			$this->assertContains($expected, $types, $expected . ' must be a supported object type.');
		}
	}//end testSupportedTypesCoverKnownObjectTypes()
}//end class
