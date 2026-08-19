<?php

/**
 * Unit tests for NlFleetChecks.
 *
 * Pins the `nl-bijtelling-auto-privegebruik` predicate (design.md D5, spec.md
 * REQ-FLEET-004): vacuous when `assetAssignmentId` is null or either the
 * AssetAssignment/Asset reference is dangling, else cents-exact
 * `Payslip.bijtelling === recomputed monthly bijtelling` (REQ-FLEET-003's
 * formula, re-derived from the referenced AssetAssignment/Asset --
 * hrmq-asset-fleet-merge, renamed from the retired CarAssignment/Vehicle
 * schemas). Also pins `nl-asset-voertuig-fiscale-velden-compleet` (Asset,
 * hrmq-asset-fleet-merge design.md D2): fires when `category: vehicle` and
 * any of `listPrice`/`fuelType`/`companyCarTaxCategory` is absent. The suite
 * closes with a REAL `RuleEngine::evaluate()` integration test proving both
 * rules are genuinely reachable via `occ hrmq:rules:audit`, not an orphaned
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
 * @spec openspec/changes/hrmq-asset-fleet-merge/specs/fleet-bijtelling/spec.md#REQ-FLEET-004
 * @spec openspec/changes/hrmq-asset-fleet-merge/specs/asset-management/spec.md#REQ-AST-001
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Standards\Checks;

use OCA\Hrmq\Standards\Checks\NlFleetChecks;
use OCA\Hrmq\Standards\RuleEngine;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NlFleetChecks (raw predicates + through the REAL RuleEngine).
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
	 * The design.md D4 anchor vehicle-category Asset fixture (listPrice
	 * €45.000,00, companyCarTaxCategory standard/22%; hrmq-asset-fleet-merge,
	 * renamed from the retired Vehicle schema's `vehicle()` fixture),
	 * overridable per test.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function vehicleAsset(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'veh-1',
				'name' => 'Tesla Model Y',
				'category' => 'vehicle',
				'listPrice' => 45000.00,
				'fuelType' => 'fullyElectric',
				'companyCarTaxCategory' => 'standard',
			],
			$overrides
		);

	}//end vehicleAsset()

	/**
	 * The design.md D4 anchor AssetAssignment fixture (employeeContribution
	 * €325,00; hrmq-asset-fleet-merge, renamed from the retired CarAssignment
	 * schema's `carAssignment()` fixture), overridable per test.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function assetAssignment(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'ca-1',
				'assetId' => 'veh-1',
				'employeeId' => 'emp-1',
				'issuedOn' => '2026-01-01',
				'returnedOn' => null,
				'employeeContribution' => 325.00,
			],
			$overrides
		);

	}//end assetAssignment()

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
				'assetAssignmentId' => 'ca-1',
				'bijtelling' => 500.00,
			],
			$overrides
		);

	}//end payslip()

	/**
	 * A `context['related']['AssetAssignment']['byId']`/
	 * `['Asset']['byId']` fixture (hrmq-asset-fleet-merge, the retired
	 * `context['fleet']` shape's replacement).
	 *
	 * @param array<int, array<string, mixed>> $assetAssignments The AssetAssignment rows.
	 * @param array<int, array<string, mixed>> $assets The Asset rows.
	 *
	 * @return array<string, mixed>
	 */
	private function context(array $assetAssignments, array $assets): array {
		$assetAssignmentsById = [];
		foreach ($assetAssignments as $assignment) {
			$assetAssignmentsById[(string)$assignment['id']] = $assignment;
		}

		$assetsById = [];
		foreach ($assets as $asset) {
			$assetsById[(string)$asset['id']] = $asset;
		}

		return [
			'jurisdiction' => 'NL',
			'related' => [
				'AssetAssignment' => ['byId' => $assetAssignmentsById],
				'Asset' => ['byId' => $assetsById],
			],
		];

	}//end context()

	/**
	 * REQ-FLEET-004 Scenario 3 — a Payslip with `assetAssignmentId` null (no
	 * company car covered this period) is vacuously compliant (out of
	 * scope), whether or not fleet data exists in the context.
	 *
	 * @return void
	 */
	public function testNullAssetAssignmentIdIsVacuouslyCompliant(): void {
		$check = $this->checks['Payslip']['nl-bijtelling-auto-privegebruik'];

		self::assertTrue($check($this->payslip(['assetAssignmentId' => null, 'bijtelling' => null]), []));
		self::assertTrue(
			$check(
				$this->payslip(['assetAssignmentId' => '', 'bijtelling' => null]),
				$this->context([$this->assetAssignment()], [$this->vehicleAsset()])
			)
		);

	}//end testNullAssetAssignmentIdIsVacuouslyCompliant()

	/**
	 * A Payslip whose `assetAssignmentId` cannot be resolved (dangling
	 * reference) is vacuously compliant -- a different, pre-existing class
	 * of data-integrity problem, not this rule's job.
	 *
	 * @return void
	 */
	public function testDanglingAssetAssignmentReferenceIsVacuouslyCompliant(): void {
		$check = $this->checks['Payslip']['nl-bijtelling-auto-privegebruik'];

		self::assertTrue($check($this->payslip(['assetAssignmentId' => 'ca-ghost']), $this->context([$this->assetAssignment()], [$this->vehicleAsset()])));

	}//end testDanglingAssetAssignmentReferenceIsVacuouslyCompliant()

	/**
	 * A Payslip whose AssetAssignment resolves but whose `assetId` is
	 * dangling is likewise vacuously compliant.
	 *
	 * @return void
	 */
	public function testDanglingAssetReferenceIsVacuouslyCompliant(): void {
		$check = $this->checks['Payslip']['nl-bijtelling-auto-privegebruik'];

		$assignment = $this->assetAssignment(['assetId' => 'veh-ghost']);
		self::assertTrue($check($this->payslip(), $this->context([$assignment], [$this->vehicleAsset()])));

	}//end testDanglingAssetReferenceIsVacuouslyCompliant()

	/**
	 * REQ-FLEET-004 Scenario 1 — the correctly computed design.md D4 anchor
	 * payslip audits clean: the recorded €500,00 matches the formula
	 * (listPrice €45.000,00 x 22% / 12 - €325,00 = €500,00).
	 *
	 * @return void
	 */
	public function testCorrectlyComputedBijtellingIsCompliant(): void {
		$check = $this->checks['Payslip']['nl-bijtelling-auto-privegebruik'];
		$context = $this->context([$this->assetAssignment()], [$this->vehicleAsset()]);

		self::assertTrue($check($this->payslip(), $context));

	}//end testCorrectlyComputedBijtellingIsCompliant()

	/**
	 * REQ-FLEET-004 Scenario 2 — a tampered bijtelling value (hand-edited to
	 * €600,00 while the AssetAssignment/Asset still compute to €500,00) is a
	 * violation, through the raw predicate.
	 *
	 * @return void
	 */
	public function testTamperedBijtellingViolates(): void {
		$check = $this->checks['Payslip']['nl-bijtelling-auto-privegebruik'];
		$context = $this->context([$this->assetAssignment()], [$this->vehicleAsset()]);

		self::assertFalse($check($this->payslip(['bijtelling' => 600.00]), $context));

	}//end testTamperedBijtellingViolates()

	/**
	 * design.md D3 — the two-tier `evReducedCapped` blend is correctly
	 * re-derived: listPrice €45.000,00 with the nl-2026 cap €30.000,00 ->
	 * base = 30.000 x 18% + 15.000 x 22% = 8.700,00; monthly = 725,00,
	 * employeeContribution 0 -> €725,00.
	 *
	 * @return void
	 */
	public function testElektrischGeplafonneerdBlendIsCorrectlyReDerived(): void {
		$check = $this->checks['Payslip']['nl-bijtelling-auto-privegebruik'];
		$asset = $this->vehicleAsset(['companyCarTaxCategory' => 'evReducedCapped']);
		$assignment = $this->assetAssignment(['employeeContribution' => 0.00]);
		$context = $this->context([$assignment], [$asset]);

		self::assertTrue($check($this->payslip(['bijtelling' => 725.00]), $context));
		self::assertFalse($check($this->payslip(['bijtelling' => 700.00]), $context));

	}//end testElektrischGeplafonneerdBlendIsCorrectlyReDerived()

	/**
	 * A pre-existing hand-entered Payslip (`assetAssignmentId: null`) stays
	 * out of scope, through the REAL `RuleEngine::evaluate()`.
	 *
	 * @return void
	 */
	public function testRealRuleEngineIsSilentForHandEnteredPayslips(): void {
		$violations = RuleEngine::evaluate('Payslip', $this->payslip(['assetAssignmentId' => null, 'bijtelling' => null]), []);

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
		$context = $this->context([$this->assetAssignment()], [$this->vehicleAsset()]);
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
		$context = $this->context([$this->assetAssignment()], [$this->vehicleAsset()]);
		$violations = RuleEngine::evaluate('Payslip', $this->payslip(), $context);

		$ruleIds = array_map(static fn ($v) => $v->ruleId, $violations);
		self::assertNotContains('nl-bijtelling-auto-privegebruik', $ruleIds);

	}//end testRealRuleEngineIsSilentWhenTheBijtellingMatches()

	// -- nl-asset-voertuig-fiscale-velden-compleet (hrmq-asset-fleet-merge D2) --

	/**
	 * A `category: vehicle` Asset carrying all three fiscal fields is
	 * compliant.
	 *
	 * @return void
	 */
	public function testCompleteVehicleAssetIsCompliant(): void {
		$check = $this->checks['Asset']['nl-asset-voertuig-fiscale-velden-compleet'];

		self::assertTrue($check($this->vehicleAsset(), []));

	}//end testCompleteVehicleAssetIsCompliant()

	/**
	 * A non-`vehicle` Asset is vacuously compliant regardless of whether the
	 * three fiscal fields are present -- the conditional never fires outside
	 * category vehicle.
	 *
	 * @return void
	 */
	public function testNonVehicleAssetIsVacuouslyCompliant(): void {
		$check = $this->checks['Asset']['nl-asset-voertuig-fiscale-velden-compleet'];

		self::assertTrue($check(['id' => 'a-1', 'name' => 'Werkschoenen', 'category' => 'clothing'], []));

	}//end testNonVehicleAssetIsVacuouslyCompliant()

	/**
	 * spec.md REQ-AST-001 scenario "A vehicle Asset without fiscal facts is
	 * reported by the rule audit" (design.md D2's positive control, tasks
	 * 1.5c/3.2b): a `category: vehicle` Asset missing `listPrice` violates
	 * the raw predicate -- proving the rule genuinely CAN fail, not merely
	 * that it has only ever reported zero.
	 *
	 * @return void
	 */
	public function testVehicleAssetMissingListPriceViolates(): void {
		$check = $this->checks['Asset']['nl-asset-voertuig-fiscale-velden-compleet'];

		$asset = $this->vehicleAsset();
		unset($asset['listPrice']);
		self::assertFalse($check($asset, []));

		self::assertFalse($check($this->vehicleAsset(['listPrice' => null]), []));

	}//end testVehicleAssetMissingListPriceViolates()

	/**
	 * The same violation for a missing `fuelType`.
	 *
	 * @return void
	 */
	public function testVehicleAssetMissingFuelTypeViolates(): void {
		$check = $this->checks['Asset']['nl-asset-voertuig-fiscale-velden-compleet'];

		self::assertFalse($check($this->vehicleAsset(['fuelType' => null]), []));

	}//end testVehicleAssetMissingFuelTypeViolates()

	/**
	 * The same violation for a missing `companyCarTaxCategory`.
	 *
	 * @return void
	 */
	public function testVehicleAssetMissingCompanyCarTaxCategoryViolates(): void {
		$check = $this->checks['Asset']['nl-asset-voertuig-fiscale-velden-compleet'];

		self::assertFalse($check($this->vehicleAsset(['companyCarTaxCategory' => null]), []));

	}//end testVehicleAssetMissingCompanyCarTaxCategoryViolates()

	/**
	 * spec.md REQ-AST-001, through the REAL `RuleEngine::evaluate()`,
	 * proving `nl-asset-voertuig-fiscale-velden-compleet` is genuinely
	 * reachable via `occ hrmq:rules:audit` and not an orphaned capability.
	 *
	 * @return void
	 */
	public function testRealRuleEngineFiresTheIncompleteVehicleAssetViolation(): void {
		$asset = $this->vehicleAsset(['listPrice' => null]);
		$violations = RuleEngine::evaluate('Asset', $asset, []);

		$ruleIds = array_map(static fn ($v) => $v->ruleId, $violations);
		self::assertContains('nl-asset-voertuig-fiscale-velden-compleet', $ruleIds, 'The real RuleEngine must fire the incomplete-vehicle-Asset violation.');

	}//end testRealRuleEngineFiresTheIncompleteVehicleAssetViolation()

	/**
	 * The mirror-image REAL RuleEngine check: a complete vehicle Asset
	 * produces NO violation.
	 *
	 * @return void
	 */
	public function testRealRuleEngineIsSilentForACompleteVehicleAsset(): void {
		$violations = RuleEngine::evaluate('Asset', $this->vehicleAsset(), []);

		$ruleIds = array_map(static fn ($v) => $v->ruleId, $violations);
		self::assertNotContains('nl-asset-voertuig-fiscale-velden-compleet', $ruleIds);

	}//end testRealRuleEngineIsSilentForACompleteVehicleAsset()

}//end class
