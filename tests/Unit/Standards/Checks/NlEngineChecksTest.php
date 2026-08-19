<?php

/**
 * Unit tests for the payroll-engine contract checks (NlEngineChecks).
 *
 * Pins all three payroll-core predicates (design.md D7 + audit-trail-payroll
 * REQ-AUDP-005): `nl-engine-table-version` (hand-entered runs vacuous; engine
 * runs need calculatedAt + an existing versioned table file);
 * `nl-engine-output-consistency` (hand-entered / unresolvable-run payslips
 * vacuous; engine payslips reconcile cents-exact to nettoPay = grossPay -
 * loonheffing - pensionContribution(null->0) - (zvw if zvwMode =
 * inhouding)); and `nl-engine-provenance-complete` (same vacuous scoping;
 * engine payslips need a present, valid, jurisdiction-consistent
 * `engineInputSnapshot`, fixing hrmq#98).
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Standards\Checks
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
 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-007
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Standards\Checks;

use OCA\Hrmq\Standards\Checks\NlEngineChecks;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NlEngineChecks.
 *
 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-007
 */
class NlEngineChecksTest extends TestCase {

	/**
	 * The registered predicates.
	 *
	 * @var array<string, array<string, callable>>
	 */
	private array $checks;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->checks = NlEngineChecks::checks();

	}//end setUp()

	/**
	 * A `context['payroll']['runsById']` fixture.
	 *
	 * @param array<string, array<string, mixed>> $runsById Runs keyed by id.
	 *
	 * @return array<string, mixed>
	 */
	private function context(array $runsById = []): array {
		return ['payroll' => ['runsById' => $runsById]];
	}//end context()

	/**
	 * An engine payslip satisfying the net equation, overridable per test.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function payslip(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'ps-1',
				'payrollRunId' => 'run-1',
				'grossPay' => 3800.00,
				'loonheffing' => 718.83,
				'pensionContribution' => 0.00,
				'zvw' => 231.80,
				'zvwMode' => 'werkgeversheffing',
				'nettoPay' => 3081.17,
			],
			$overrides
		);

	}//end payslip()

	/**
	 * @return void
	 */
	public function testHandEnteredRunWithoutEngineVersionIsVacuouslyCompliant(): void {
		$check = $this->checks['PayrollRun']['nl-engine-table-version'];

		$this->assertTrue($check(['status' => 'posted'], []));
		$this->assertTrue($check(['engineVersion' => null, 'calculatedAt' => null], []));

	}//end testHandEnteredRunWithoutEngineVersionIsVacuouslyCompliant()

	/**
	 * @return void
	 */
	public function testEngineRunWithExistingTableAndCalculatedAtIsCompliant(): void {
		$check = $this->checks['PayrollRun']['nl-engine-table-version'];

		$run = ['engineVersion' => 'nl-2026', 'calculatedAt' => '2026-07-14T10:00:00Z'];
		$this->assertTrue($check($run, []));

	}//end testEngineRunWithExistingTableAndCalculatedAtIsCompliant()

	/**
	 * @return void
	 */
	public function testEngineRunWithNonExistentTableVersionViolates(): void {
		$check = $this->checks['PayrollRun']['nl-engine-table-version'];

		$run = ['engineVersion' => 'nl-2031', 'calculatedAt' => '2026-07-14T10:00:00Z'];
		$this->assertFalse($check($run, []));

	}//end testEngineRunWithNonExistentTableVersionViolates()

	/**
	 * @return void
	 */
	public function testEngineRunWithoutCalculatedAtViolates(): void {
		$check = $this->checks['PayrollRun']['nl-engine-table-version'];

		$run = ['engineVersion' => 'nl-2026', 'calculatedAt' => null];
		$this->assertFalse($check($run, []));

	}//end testEngineRunWithoutCalculatedAtViolates()

	/**
	 * @return void
	 */
	public function testHandEnteredPayslipWithoutPayrollRunIdIsVacuouslyCompliant(): void {
		$check = $this->checks['Payslip']['nl-engine-output-consistency'];

		// Even a broken net equation stays out of scope without a run link.
		$payslip = $this->payslip(['payrollRunId' => null, 'nettoPay' => 1.00]);
		$this->assertTrue($check($payslip, $this->context()));

	}//end testHandEnteredPayslipWithoutPayrollRunIdIsVacuouslyCompliant()

	/**
	 * @return void
	 */
	public function testPayslipOfHandEnteredOrUnresolvableRunIsVacuouslyCompliant(): void {
		$check = $this->checks['Payslip']['nl-engine-output-consistency'];

		$broken = $this->payslip(['nettoPay' => 1.00]);

		// Run resolvable but hand-entered (no engineVersion).
		$this->assertTrue($check($broken, $this->context(['run-1' => ['id' => 'run-1', 'status' => 'draft']])));
		// Run unresolvable (empty context).
		$this->assertTrue($check($broken, $this->context()));
		$this->assertTrue($check($broken, []));

	}//end testPayslipOfHandEnteredOrUnresolvableRunIsVacuouslyCompliant()

	/**
	 * @return void
	 */
	public function testConsistentEnginePayslipSatisfiesTheEquation(): void {
		$check = $this->checks['Payslip']['nl-engine-output-consistency'];
		$context = $this->context(['run-1' => ['id' => 'run-1', 'engineVersion' => 'nl-2026']]);

		$this->assertTrue($check($this->payslip(), $context));

		// pensionContribution null -> 0 (the equation's null->0 rule).
		$this->assertTrue($check($this->payslip(['pensionContribution' => null]), $context));

	}//end testConsistentEnginePayslipSatisfiesTheEquation()

	/**
	 * @return void
	 */
	public function testTamperedNettoPayViolatesTheEquation(): void {
		$check = $this->checks['Payslip']['nl-engine-output-consistency'];
		$context = $this->context(['run-1' => ['id' => 'run-1', 'engineVersion' => 'nl-2026']]);

		$this->assertFalse($check($this->payslip(['nettoPay' => 3081.18]), $context));

	}//end testTamperedNettoPayViolatesTheEquation()

	/**
	 * @return void
	 */
	public function testInhoudingModeSubtractsZvwFromNet(): void {
		$check = $this->checks['Payslip']['nl-engine-output-consistency'];
		$context = $this->context(['run-1' => ['id' => 'run-1', 'engineVersion' => 'nl-2026']]);

		// 3800 - 718.83 - 0 - 184.30 (zvw withheld) = 2896.87.
		$inhouding = $this->payslip(['zvwMode' => 'inhouding', 'zvw' => 184.30, 'nettoPay' => 2896.87]);
		$this->assertTrue($check($inhouding, $context));

		// The werkgeversheffing net (which ignores zvw) is now inconsistent.
		$wrongNet = $this->payslip(['zvwMode' => 'inhouding', 'zvw' => 184.30]);
		$this->assertFalse($check($wrongNet, $context));

	}//end testInhoudingModeSubtractsZvwFromNet()

	/**
	 * @return void
	 */
	public function testMissingComponentsOnAnEnginePayslipViolate(): void {
		$check = $this->checks['Payslip']['nl-engine-output-consistency'];
		$context = $this->context(['run-1' => ['id' => 'run-1', 'engineVersion' => 'nl-2026']]);

		$this->assertFalse($check($this->payslip(['grossPay' => null]), $context));
		$this->assertFalse($check($this->payslip(['loonheffing' => null]), $context));
		$this->assertFalse($check($this->payslip(['nettoPay' => null]), $context));

	}//end testMissingComponentsOnAnEnginePayslipViolate()

	// -----------------------------------------------------------------
	// nl-engine-provenance-complete (audit-trail-payroll REQ-AUDP-005,
	// fixing hrmq#98).
	// -----------------------------------------------------------------

	/**
	 * @return void
	 */
	public function testHandEnteredPayslipWithoutPayrollRunIdIsVacuouslyCompliantForProvenance(): void {
		$check = $this->checks['Payslip']['nl-engine-provenance-complete'];

		$payslip = $this->payslip(['payrollRunId' => null, 'engineInputSnapshot' => null]);
		$this->assertTrue($check($payslip, $this->context()));

	}//end testHandEnteredPayslipWithoutPayrollRunIdIsVacuouslyCompliantForProvenance()

	/**
	 * @return void
	 */
	public function testUnresolvableOrHandEnteredRunIsVacuouslyCompliantForProvenance(): void {
		$check = $this->checks['Payslip']['nl-engine-provenance-complete'];

		$payslip = $this->payslip(['engineInputSnapshot' => null]);

		// Run resolvable but hand-entered (no engineVersion).
		$this->assertTrue($check($payslip, $this->context(['run-1' => ['id' => 'run-1', 'status' => 'draft']])));
		// Run unresolvable (empty context).
		$this->assertTrue($check($payslip, $this->context()));
		$this->assertTrue($check($payslip, []));

	}//end testUnresolvableOrHandEnteredRunIsVacuouslyCompliantForProvenance()

	/**
	 * @return void
	 */
	public function testFreshlyGeneratedPayslipWithAConsistentSnapshotPasses(): void {
		$check = $this->checks['Payslip']['nl-engine-provenance-complete'];
		$context = $this->context(['run-1' => ['id' => 'run-1', 'engineVersion' => 'nl-2026@1.0.0']]);

		$payslip = $this->payslip(['engineInputSnapshot' => '{"jurisdiction":"NL","period":"2026-02"}']);
		$this->assertTrue($check($payslip, $context));

	}//end testFreshlyGeneratedPayslipWithAConsistentSnapshotPasses()

	/**
	 * @return void
	 */
	public function testEnginePayslipStrippedOfItsSnapshotViolatesProvenance(): void {
		$check = $this->checks['Payslip']['nl-engine-provenance-complete'];
		$context = $this->context(['run-1' => ['id' => 'run-1', 'engineVersion' => 'nl-2026@1.0.0']]);

		$payslip = $this->payslip(['engineInputSnapshot' => null]);
		$this->assertFalse($check($payslip, $context));

		$blank = $this->payslip(['engineInputSnapshot' => '']);
		$this->assertFalse($check($blank, $context));

	}//end testEnginePayslipStrippedOfItsSnapshotViolatesProvenance()

	/**
	 * @return void
	 */
	public function testEnginePayslipWithInvalidJsonSnapshotViolatesProvenance(): void {
		$check = $this->checks['Payslip']['nl-engine-provenance-complete'];
		$context = $this->context(['run-1' => ['id' => 'run-1', 'engineVersion' => 'nl-2026@1.0.0']]);

		$payslip = $this->payslip(['engineInputSnapshot' => 'not-json{']);
		$this->assertFalse($check($payslip, $context));

	}//end testEnginePayslipWithInvalidJsonSnapshotViolatesProvenance()

	/**
	 * @return void
	 */
	public function testSnapshotJurisdictionInconsistentWithTheRunsArtefactViolatesProvenance(): void {
		$check = $this->checks['Payslip']['nl-engine-provenance-complete'];
		$context = $this->context(['run-1' => ['id' => 'run-1', 'engineVersion' => 'nl-2026@1.0.0']]);

		// The bundled nl-2026 pack declares jurisdiction NL -- a snapshot
		// claiming DE is internally inconsistent with its own run.
		$payslip = $this->payslip(['engineInputSnapshot' => '{"jurisdiction":"DE","period":"2026-02"}']);
		$this->assertFalse($check($payslip, $context));

	}//end testSnapshotJurisdictionInconsistentWithTheRunsArtefactViolatesProvenance()

	/**
	 * An unresolvable artefact (legacy bare table-id stamp, or a since-
	 * deleted pack) stays vacuous under THIS predicate -- `nl-engine-table-
	 * version` already flags the unresolvable artefact under its own rule
	 * id; this predicate does not double-penalize the same root cause.
	 *
	 * @return void
	 */
	public function testUnresolvableArtefactStaysVacuousUnderProvenance(): void {
		$check = $this->checks['Payslip']['nl-engine-provenance-complete'];

		$legacyContext = $this->context(['run-1' => ['id' => 'run-1', 'engineVersion' => 'nl-2026']]);
		$legacy = $this->payslip(['engineInputSnapshot' => '{"jurisdiction":"NL"}']);
		$this->assertTrue($check($legacy, $legacyContext));

		$deletedContext = $this->context(['run-1' => ['id' => 'run-1', 'engineVersion' => 'nl-2099@1.0.0']]);
		$deleted = $this->payslip(['engineInputSnapshot' => '{"jurisdiction":"NL"}']);
		$this->assertTrue($check($deleted, $deletedContext));

	}//end testUnresolvableArtefactStaysVacuousUnderProvenance()

}//end class
