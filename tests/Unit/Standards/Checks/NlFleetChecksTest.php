<?php

/**
 * Unit tests for NlFleetChecks.
 *
 * Pins the `nl-bijtelling-auto-privegebruik` predicate (design.md D5, spec.md
 * REQ-FLEET-004): vacuous when `carAssignmentId` is null or either the
 * CarAssignment/Vehicle reference is dangling, else cents-exact
 * `Payslip.bijtelling === recomputed monthly bijtelling` (REQ-FLEET-003's
 * formula, re-derived from the referenced CarAssignment/Vehicle). The suite
 * closes with a REAL `RuleEngine::evaluate()` integration test proving the
 * rule is genuinely reachable via `occ hrmq:rules:audit`, not an orphaned
 * capability (the `NlWageGarnishmentChecksTest` precedent).
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
 * @spec openspec/changes/fleet-bijtelling/specs/fleet-bijtelling/spec.md#REQ-FLEET-004
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Standards\Checks;

use OCA\Hrmq\Standards\Checks\NlFleetChecks;
use OCA\Hrmq\Standards\RuleEngine;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NlFleetChecks (raw predicate + through the REAL RuleEngine).
 *
 * @spec openspec/changes/fleet-bijtelling/specs/fleet-bijtelling/spec.md#REQ-FLEET-004
 */
class NlFleetChecksTest extends TestCase {

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
		RuleEngine::reset();
		$this->checks = NlFleetChecks::checks();

	}//end setUp()

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		RuleEngine::reset();

	}//end tearDown()

	/**
	 * The design.md D4 anchor Vehicle fixture (cataloguswaarde €45.000,00,
	 * bijtellingCategorie standaard/22%), overridable per test.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function vehicle(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'veh-1',
				'name' => 'Tesla Model Y',
				'cataloguswaarde' => 45000.00,
				'fuelType' => 'volledigElektrisch',
				'bijtellingCategorie' => 'standaard',
			],
			$overrides
		);

	}//end vehicle()

	/**
	 * The design.md D4 anchor CarAssignment fixture (eigenBijdrage €325,00),
	 * overridable per test.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function carAssignment(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'ca-1',
				'vehicleId' => 'veh-1',
				'employeeId' => 'emp-1',
				'effectiveFrom' => '2026-01-01',
				'effectiveTo' => null,
				'eigenBijdrage' => 325.00,
			],
			$overrides
		);

	}//end carAssignment()

	/**
	 * The design.md D4 anchor Payslip fixture (bijtelling €500,00, correctly
	 * recorded), overridable per test.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function payslip(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'ps-1',
				'employeeId' => 'emp-1',
				'period' => '2026-02',
				'grossPay' => 4300.00,
				'nettoPay' => 3329.17,
				'carAssignmentId' => 'ca-1',
				'bijtelling' => 500.00,
			],
			$overrides
		);

	}//end payslip()

	/**
	 * A `context['fleet']['carAssignmentsById']`/`['vehiclesById']` fixture.
	 *
	 * @param array<int, array<string, mixed>> $carAssignments The CarAssignment rows.
	 * @param array<int, array<string, mixed>> $vehicles The Vehicle rows.
	 *
	 * @return array<string, mixed>
	 */
	private function context(array $carAssignments, array $vehicles): array {
		$carAssignmentsById = [];
		foreach ($carAssignments as $assignment) {
			$carAssignmentsById[(string)$assignment['id']] = $assignment;
		}

		$vehiclesById = [];
		foreach ($vehicles as $vehicle) {
			$vehiclesById[(string)$vehicle['id']] = $vehicle;
		}

		return [
			'jurisdiction' => 'NL',
			'fleet' => [
				'carAssignmentsById' => $carAssignmentsById,
				'vehiclesById' => $vehiclesById,
			],
		];

	}//end context()

	/**
	 * REQ-FLEET-004 Scenario 3 — a Payslip with `carAssignmentId` null (no
	 * company car covered this period) is vacuously compliant (out of
	 * scope), whether or not fleet data exists in the context.
	 *
	 * @return void
	 */
	public function testNullCarAssignmentIdIsVacuouslyCompliant(): void {
		$check = $this->checks['Payslip']['nl-bijtelling-auto-privegebruik'];

		self::assertTrue($check($this->payslip(['carAssignmentId' => null, 'bijtelling' => null]), []));
		self::assertTrue(
			$check(
				$this->payslip(['carAssignmentId' => '', 'bijtelling' => null]),
				$this->context([$this->carAssignment()], [$this->vehicle()])
			)
		);

	}//end testNullCarAssignmentIdIsVacuouslyCompliant()

	/**
	 * A Payslip whose `carAssignmentId` cannot be resolved (dangling
	 * reference) is vacuously compliant -- a different, pre-existing class
	 * of data-integrity problem, not this rule's job.
	 *
	 * @return void
	 */
	public function testDanglingCarAssignmentReferenceIsVacuouslyCompliant(): void {
		$check = $this->checks['Payslip']['nl-bijtelling-auto-privegebruik'];

		self::assertTrue($check($this->payslip(['carAssignmentId' => 'ca-ghost']), $this->context([$this->carAssignment()], [$this->vehicle()])));

	}//end testDanglingCarAssignmentReferenceIsVacuouslyCompliant()

	/**
	 * A Payslip whose CarAssignment resolves but whose `vehicleId` is
	 * dangling is likewise vacuously compliant.
	 *
	 * @return void
	 */
	public function testDanglingVehicleReferenceIsVacuouslyCompliant(): void {
		$check = $this->checks['Payslip']['nl-bijtelling-auto-privegebruik'];

		$assignment = $this->carAssignment(['vehicleId' => 'veh-ghost']);
		self::assertTrue($check($this->payslip(), $this->context([$assignment], [$this->vehicle()])));

	}//end testDanglingVehicleReferenceIsVacuouslyCompliant()

	/**
	 * REQ-FLEET-004 Scenario 1 — the correctly computed design.md D4 anchor
	 * payslip audits clean: the recorded €500,00 matches the formula
	 * (cataloguswaarde €45.000,00 x 22% / 12 - €325,00 = €500,00).
	 *
	 * @return void
	 */
	public function testCorrectlyComputedBijtellingIsCompliant(): void {
		$check = $this->checks['Payslip']['nl-bijtelling-auto-privegebruik'];
		$context = $this->context([$this->carAssignment()], [$this->vehicle()]);

		self::assertTrue($check($this->payslip(), $context));

	}//end testCorrectlyComputedBijtellingIsCompliant()

	/**
	 * REQ-FLEET-004 Scenario 2 — a tampered bijtelling value (hand-edited to
	 * €600,00 while the CarAssignment/Vehicle still compute to €500,00) is a
	 * violation, through the raw predicate.
	 *
	 * @return void
	 */
	public function testTamperedBijtellingViolates(): void {
		$check = $this->checks['Payslip']['nl-bijtelling-auto-privegebruik'];
		$context = $this->context([$this->carAssignment()], [$this->vehicle()]);

		self::assertFalse($check($this->payslip(['bijtelling' => 600.00]), $context));

	}//end testTamperedBijtellingViolates()

	/**
	 * design.md D3 — the two-tier `elektrischGeplafonneerd` blend is
	 * correctly re-derived: cataloguswaarde €45.000,00 with the nl-2026 cap
	 * €30.000,00 -> base = 30.000 x 18% + 15.000 x 22% = 8.700,00; monthly =
	 * 725,00, eigenBijdrage 0 -> €725,00.
	 *
	 * @return void
	 */
	public function testElektrischGeplafonneerdBlendIsCorrectlyReDerived(): void {
		$check = $this->checks['Payslip']['nl-bijtelling-auto-privegebruik'];
		$vehicle = $this->vehicle(['bijtellingCategorie' => 'elektrischGeplafonneerd']);
		$assignment = $this->carAssignment(['eigenBijdrage' => 0.00]);
		$context = $this->context([$assignment], [$vehicle]);

		self::assertTrue($check($this->payslip(['bijtelling' => 725.00]), $context));
		self::assertFalse($check($this->payslip(['bijtelling' => 700.00]), $context));

	}//end testElektrischGeplafonneerdBlendIsCorrectlyReDerived()

	/**
	 * A pre-existing hand-entered Payslip (`carAssignmentId: null`) stays out
	 * of scope, through the REAL `RuleEngine::evaluate()`.
	 *
	 * @return void
	 */
	public function testRealRuleEngineIsSilentForHandEnteredPayslips(): void {
		$violations = RuleEngine::evaluate('Payslip', $this->payslip(['carAssignmentId' => null, 'bijtelling' => null]), []);

		$ruleIds = array_map(static fn ($v) => $v->ruleId, $violations);
		self::assertNotContains('nl-bijtelling-auto-privegebruik', $ruleIds);

	}//end testRealRuleEngineIsSilentForHandEnteredPayslips()

	/**
	 * REQ-FLEET-004 Scenario 2, through the REAL `RuleEngine::evaluate()`
	 * (catalogue + auto-discovered CheckProviders), proving
	 * `nl-bijtelling-auto-privegebruik` is genuinely reachable via
	 * `occ hrmq:rules:audit` and not an orphaned capability.
	 *
	 * @return void
	 */
	public function testRealRuleEngineFiresTheTamperedBijtellingViolation(): void {
		$context = $this->context([$this->carAssignment()], [$this->vehicle()]);
		$violations = RuleEngine::evaluate('Payslip', $this->payslip(['bijtelling' => 600.00]), $context);

		$ruleIds = array_map(static fn ($v) => $v->ruleId, $violations);
		self::assertContains('nl-bijtelling-auto-privegebruik', $ruleIds, 'The real RuleEngine must fire the tampered-bijtelling violation.');

	}//end testRealRuleEngineFiresTheTamperedBijtellingViolation()

	/**
	 * The mirror-image REAL RuleEngine check: the correctly computed anchor
	 * payslip produces NO violation.
	 *
	 * @return void
	 */
	public function testRealRuleEngineIsSilentWhenTheBijtellingMatches(): void {
		$context = $this->context([$this->carAssignment()], [$this->vehicle()]);
		$violations = RuleEngine::evaluate('Payslip', $this->payslip(), $context);

		$ruleIds = array_map(static fn ($v) => $v->ruleId, $violations);
		self::assertNotContains('nl-bijtelling-auto-privegebruik', $ruleIds);

	}//end testRealRuleEngineIsSilentWhenTheBijtellingMatches()

}//end class
